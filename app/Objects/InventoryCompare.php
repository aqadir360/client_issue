<?php

namespace App\Objects;

use App\Models\Inventory;
use App\Models\Location;
use App\Models\Product;

// Compares existing inventory to file set
class InventoryCompare
{
    /** @var Api */
    private $proxy;

    /** @var ImportManager */
    private $import;

    private $companyId;
    private $storeId;

    private $inventoryLookup = [];
    private $fileItemsLookup = [];

    private $updateLocations = true;
    private $updateDepartments = true;

    private $totalExistingItems = 0;
    private $maxAllowedDiscoPercent = 40;

    public function __construct(ImportManager $import, string $storeId)
    {
        $this->companyId = $import->companyId();
        $this->storeId = $storeId;
        $this->proxy = $import->getProxy();
        $this->import = $import;
    }

    public function setExistingInventory()
    {
        $this->inventoryLookup = [];
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
        }

        $this->import->outputContent($this->totalExistingItems . " existing inventory items");
    }

    // Moves any products with a 1:1 match
    // Creates items found in file but not existing inventory
    // Discontinues items in existing inventory but not in file if below allowed disco percent
    public function compareInventorySets(bool $updateLocations = true, bool $updateDepartments = true)
    {
        $this->updateLocations = $updateLocations;
        $this->updateDepartments = $updateDepartments;

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

    public function setFileInventoryItem(Product $product, Location $location, $deptId)
    {
        // Sort by barcode, then include only one item per aisle
        $this->fileItemsLookup[intval($product->barcode)][$location->aisle] = new Inventory(
            $product,
            $location,
            $deptId
        );
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
            foreach ($items as $item) {
                $this->createNewItem($barcode, $item, $item->location);
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

    private function handleOneToOneMatch($barcode, array $items)
    {
        $newItem = array_shift($items);
        $this->moveItem($this->inventoryLookup[$barcode][0], $newItem);

        unset($this->fileItemsLookup[$barcode]);
        $this->inventoryLookup[$barcode][0]['found'] = true;
    }

    private function handleUnequalMatch($barcode, array $items)
    {
        // Handle all items that have existing matches
        foreach ($items as $aisle => $item) {
            foreach ($this->inventoryLookup[$barcode] as $i => $existingItem) {
                if ($aisle === $existingItem['aisle']) {
                    $this->inventoryLookup[$barcode][$i]['found'] = true;
                    $this->moveItem($this->inventoryLookup[$barcode][$i], $item);
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
                    $this->moveItem($existingItem, $remainingItem);
                    unset($this->fileItemsLookup[$barcode][$aisle]);
                    break;
                }
            }
        }

        if (count($this->fileItemsLookup[$barcode]) === 0) {
            unset($this->fileItemsLookup[$barcode]);
        }
    }

    private function moveItem($existingItem, Inventory $newItem)
    {
        if ($this->shouldMoveItem($existingItem, $newItem) && $newItem->location->valid) {
            if ($this->updateLocations === false && $this->updateDepartments === false) {
                $this->import->recordStatic();
                $this->import->writeFileOutput([$newItem->product->barcode], "Static: Skipping Moves");
            } else {
                $departmentId = $this->updateDepartments ? $newItem->departmentId : $existingItem['departmentId'];

                if ($this->updateLocations) {
                    $updateLocation = $newItem->location;
                } else {
                    $updateLocation = new Location(
                        $existingItem['aisle'],
                        $existingItem['section'],
                        $existingItem['shelf']
                    );
                }

                if ($existingItem['departmentId'] === $departmentId
                    && $existingItem['aisle'] === $updateLocation->aisle
                    && $existingItem['section'] === $updateLocation->section
                    && $existingItem['shelf'] === $updateLocation->shelf
                ) {
                    $this->import->recordStatic();
                    $this->import->writeFileOutput([$newItem->product->barcode], "Static");
                    return;
                }

                $this->import->updateInventoryLocation(
                    $existingItem['id'],
                    $this->storeId,
                    $departmentId,
                    $updateLocation->aisle,
                    $updateLocation->section,
                    $updateLocation->shelf
                );

                $this->import->writeFileOutput([$newItem->product->barcode], "Success: Moved");
            }
        } else {
            $this->import->recordStatic();
            $this->import->writeFileOutput([$newItem->product->barcode], "Static: Existing Inventory");
        }
    }

    // Do not move to skipped or identical locations
    private function shouldMoveItem($existing, Inventory $item): bool
    {
        if ($this->import->shouldSkipLocation($item->location->aisle) || $item->location->valid === false) {
            return false;
        }

        // Move to new department if changed
        if ($item->departmentId !== $existing['departmentId']) {
            return true;
        }

        return !$item->matchingLocation($existing['aisle'], $existing['section'], $existing['shelf']);
    }

    private function createNewItem($barcode, Inventory $item, Location $loc)
    {
        if ($this->import->isInSkipList($barcode)) {
            return;
        }

        if ($loc->valid === false) {
            $this->import->recordSkipped();
            return;
        }

        if ($this->import->shouldSkipLocation($loc->aisle, $loc->section, $loc->shelf)) {
            return;
        }

        $result = $this->import->implementationScan(
            $item->product,
            $this->storeId,
            $loc->aisle,
            $loc->section,
            $item->departmentId,
            $loc->shelf
        );

        if ($result !== null) {
            $this->import->writeFileOutput([$item->product->barcode], "Success: Created");
        } else {
            $this->import->writeFileOutput([$item->product->barcode], "Error: Could Not Create");
        }
    }

    private function discontinue(array $item)
    {
        $response = $this->proxy->writeInventoryDisco($this->companyId, $item['id']);
        $this->import->recordResponse($response, 'disco');
        $this->import->writeFileOutput($item, "Success: Disco");
    }

    private function getPercentage($numerator, $denom): float
    {
        if (intval($denom) === 0) {
            return 0;
        }

        return floatval($numerator / $denom) * 100;
    }
}
