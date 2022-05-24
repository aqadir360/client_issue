<?php

namespace App\Objects;

use App\Models\Location;

// Compares existing inventory to file set, adding only in locations with active inventory
class InventoryCompareByLocation
{
    /** @var Api */
    private $proxy;

    /** @var ImportManager */
    private $import;

    private $companyId;
    private $storeId;

    private $inventoryLookup = [];
    private $fileItemsLookup = [];
    private $trackedLocations = [];

    private $totalExistingItems = 0;
    private $maxAllowedDiscoPercent = 70;
    private $minItemsForTrackedLoc = 3;
    private $updateDepts = true;

    public function __construct(ImportManager $import, string $storeId, bool $updateDepts = true)
    {
        $this->companyId = $import->companyId();
        $this->storeId = $storeId;
        $this->proxy = $import->getProxy();
        $this->import = $import;
        $this->updateDepts = $updateDepts;
    }

    // Gets inventory and sets tracked locations
    public function setExistingInventory()
    {
        $this->inventoryLookup = [];
        $this->trackedLocations = [];
        $this->totalExistingItems = 0;

        $inventory = $this->import->db->fetchStoreInventory($this->storeId);

        foreach ($inventory as $item) {
            $this->totalExistingItems++;

            // Sort all items by barcode
            $this->inventoryLookup[intval($item->barcode)][] = [
                'id' => $item->inventory_item_id,
                'expiration' => $item->expiration_date,
                'aisle' => $item->aisle,
                'section' => $item->section,
                'shelf' => $item->shelf,
                'departmentId' => $item->department_id,
                'barcode' => $item->barcode,
                'found' => false,
            ];

            $this->updateTrackedLocations($this->getLocKey($item->aisle, $item->section), $item->department_id);
        }

        $this->import->outputContent($this->totalExistingItems . " existing inventory items");
    }

    // Moves any products with a 1:1 match
    // Creates items found in file but not existing inventory
    // Discontinues items in existing inventory but not in file if below allowed disco percent
    public function compareInventorySets()
    {
        // Handle all products in common between both inventory sets
        foreach ($this->fileItemsLookup as $barcode => $items) {
            if (isset($this->inventoryLookup[$barcode])) {
                if (count($items) === 1 && count($this->inventoryLookup[$barcode]) === 1) {
                    $this->handleOneToOneMatch($barcode, $items);
                } else {
                    $this->handleUnequalMatch($barcode, $items);
                }
            }
        }

        $this->createMissing();
        $this->discontinueRemaining();
    }

    // Creates items found in file but not existing inventory
    // Discontinues items in existing inventory but not in file if below allowed disco percent
    public function compareInventorySetsWithoutMoves()
    {
        // Skip all products in common between both inventory sets
        foreach ($this->fileItemsLookup as $barcode => $items) {
            if (isset($this->inventoryLookup[$barcode])) {
                unset($this->fileItemsLookup[$barcode]);
                $this->inventoryLookup[$barcode][0]['found'] = true;
            }
        }

        $this->createMissing();
        $this->discontinueRemaining();
    }

    public function setFileInventoryItem(string $barcode, Location $location, $description, $size, $deptId = null)
    {
        // Sort by barcode, then include only one item per aisle
        $this->fileItemsLookup[intval($barcode)][$location->aisle] = [
            'location' => $location,
            'description' => $description,
            'size' => $size,
            'departmentId' => $deptId,
        ];
    }

    public function fileInventoryCount(): int
    {
        $count = count($this->fileItemsLookup);
        $this->import->outputContent("$count inventory items in file");
        return $count;
    }

    private function createMissing()
    {
        // Create any items from file that were not in existing inventory
        foreach ($this->fileItemsLookup as $barcode => $items) {
            foreach ($items as $aisle => $item) {
                $this->createNewItem($barcode, $item);
            }
        }
    }

    private function discontinueRemaining()
    {
        // Get all existing items not found in file
        $discoItems = [];
        foreach ($this->inventoryLookup as $items) {
            foreach ($items as $item) {
                if ($item['found'] === false) {
                    $discoItems[] = $item;
                }
            }
        }

        // Skip and output warning if over maxAllowedDiscoPercent
        $discoPercent = $this->getPercentage(count($discoItems), $this->totalExistingItems);
        if ($discoPercent > $this->maxAllowedDiscoPercent) {
            $this->import->outputContent("Skipping attempt to discontinue $discoPercent% of inventory.");
            return;
        }

        $this->import->outputContent("Disco percent: $discoPercent%");

        // Discontinue any items that were not found in file
        foreach ($discoItems as $item) {
            $this->discontinue($item);
        }
    }

    private function handleOneToOneMatch($barcode, $items)
    {
        $newItem = array_shift($items);
        $this->moveItem($this->inventoryLookup[$barcode][0], $newItem, $barcode);

        unset($this->fileItemsLookup[$barcode]);
        $this->inventoryLookup[$barcode][0]['found'] = true;
    }

    private function handleUnequalMatch($barcode, $items)
    {
        // Handle all items that have existing matches
        foreach ($items as $aisle => $item) {
            foreach ($this->inventoryLookup[$barcode] as $i => $existingItem) {
                if ($aisle == $existingItem['aisle']) {
                    $this->inventoryLookup[$barcode][$i]['found'] = true;
                    $this->moveItem($this->inventoryLookup[$barcode][$i], $item, $barcode);
                    unset($this->fileItemsLookup[$barcode][$aisle]);
                    break;
                }
            }
        }

        // Handle unmatched file items
        foreach ($this->fileItemsLookup[$barcode] as $aisle => $remainingItem) {
            foreach ($this->inventoryLookup[$barcode] as $i => $existingItem) {
                if (!$existingItem['found']) {
                    $this->inventoryLookup[$barcode][$i]['found'] = true;
                    $this->moveItem($existingItem, $remainingItem, $barcode);
                    unset($this->fileItemsLookup[$barcode][$aisle]);
                    break;
                }
            }
        }

        if (count($this->fileItemsLookup[$barcode]) === 0) {
            unset($this->fileItemsLookup[$barcode]);
        }
    }

    private function moveItem(array $existingItem, array $newItem, string $barcode)
    {
        $departmentId = $this->getDepartmentId($existingItem, $newItem);

        if ($this->shouldMoveItem($existingItem, $newItem, $departmentId) && $newItem['location']->valid) {
            $this->import->updateInventoryLocation(
                $existingItem['id'],
                $this->storeId,
                $departmentId,
                $newItem['location']->aisle,
                $newItem['location']->section,
                $newItem['location']->shelf
            );

            $this->updateTrackedLocations(
                $this->getLocKey($newItem['location']->aisle, $newItem['location']->section),
                $departmentId
            );

            $this->writeFileOutput($barcode, (string)$newItem['location'], "Success: Moved");

        } else {
            $this->import->recordStatic();
            $this->writeFileOutput($barcode, (string)$newItem['location'], "Static: Existing Inventory");
        }
    }

    private function getDepartmentId(array $existingItem, array $newItem): string
    {
        if ($this->updateDepts === false) {
            return $existingItem['departmentId'];
        }

        return $newItem['departmentId'] ?? $existingItem['departmentId'];
    }

    // Do not move to skipped or identical locations
    private function shouldMoveItem($existing, $item, $departmentId): bool
    {
        if ($this->import->shouldSkipLocation($item['location']->aisle) || $item['location']->valid === false) {
            return false;
        }

        // Move to new department if changed
        if (isset($item['departmentId']) && $departmentId !== $existing['departmentId']) {
            return true;
        }

        return !($existing['aisle'] === $item['location']->aisle
            && $existing['section'] === $item['location']->section
            && $existing['shelf'] === $item['location']->shelf);
    }

    private function createNewItem($barcode, $item)
    {
        if ($this->import->isInSkipList($barcode)) {
            return;
        }

        $loc = $item['location'];

        if ($loc->valid === false) {
            $this->import->recordSkipped();
            return;
        }

        if ($this->import->shouldSkipLocation($loc->aisle, $loc->section, $loc->shelf)) {
            return;
        }

        $locKey = $this->getLocKey($loc->aisle, $loc->section);

        if ($this->minItemsForTrackedLoc > 0) {
            // Do not add items in untracked locations
            if (!isset($this->trackedLocations[$locKey])
                || ($this->trackedLocations[$locKey]['count'] <= $this->minItemsForTrackedLoc)
            ) {
                $this->import->recordSkipped();
                return;
            }
        }

        if (!isset($item['departmentId'])) {
            // Use same department as other items in location if not included in file
            $item['departmentId'] = $this->trackedLocations[$locKey]['deptId'];
        }

        $item['barcode'] = $barcode;

        $this->implementationScan($item);
    }

    private function implementationScan($item)
    {
        $product = $this->import->fetchProduct(BarcodeFixer::fixLength($item['barcode']));

        if (!$product->isExistingProduct) {
            $product->setDescription($this->parseDescription($item['description']));
            $product->setSize($this->parseSize($item['size']));
            $this->import->createProduct($product);
        }

        $this->import->implementationScan(
            $product,
            $this->storeId,
            $item['location']->aisle,
            $item['location']->section,
            $item['departmentId'],
            $item['location']->shelf
        );
        $this->writeFileOutput($product->barcode, (string)$item['location'], "Success: Created");
    }

    private function discontinue($item)
    {
        $response = $this->proxy->writeInventoryDisco($this->companyId, $item['id']);
        $this->import->recordResponse($response, 'disco');
        $this->writeFileOutput($item['barcode'], $item['aisle'] . " " . $item['section'], "Success: Disco");
    }

    private function getLocKey($aisle, $section)
    {
        return $aisle . '-' . $section;
    }

    // Tracks how many items we have at a given location
    private function updateTrackedLocations($locKey, $deptId)
    {
        if (!isset($this->trackedLocations[$locKey])) {
            $this->trackedLocations[$locKey] = [
                'count' => 1,
                'deptId' => $deptId,
            ];
        } else {
            $this->trackedLocations[$locKey]['count']++;
        }
    }

    private function parseDescription($input)
    {
        $desc = preg_replace('/\s+/', ' ', $input);
        return ucwords(strtolower(trim($desc)));
    }

    private function parseSize($input)
    {
        return strtolower(str_replace(' ', '', $input));
    }

    private function getPercentage($numerator, $denom)
    {
        if ($denom == 0) {
            return 0;
        }

        $ratio = floatval($numerator / $denom);
        if (null == $ratio) {
            return 0;
        }
        return number_format($ratio * 100, 1);
    }

    private function writeFileOutput(string $barcode, string $location, string $message)
    {
        $this->import->writeFileOutput([$barcode, $location], $message);
    }
}
