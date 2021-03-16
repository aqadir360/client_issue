<?php

namespace App\Objects;

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
    private $trackedLocations = [];

    private $totalExistingItems = 0;
    private $maxAllowedDiscoPercent = 40;
    private $minItemsForTrackedLoc = 3;

    public function __construct(ImportManager $import, string $storeId)
    {
        $this->companyId = $import->companyId();
        $this->storeId = $storeId;
        $this->proxy = $import->getProxy();
        $this->import = $import;
    }

    // Gets inventory from the API and sets tracked locations
    public function setExistingInventory()
    {
        $this->inventoryLookup = [];
        $this->trackedLocations = [];
        $this->totalExistingItems = 0;

        $response = $this->proxy->fetchAllInventory(
            $this->companyId,
            $this->storeId
        );

        if ($this->proxy->validResponse($response)) {
            foreach ($response->data->items as $item) {
                $this->totalExistingItems++;

                // Sort all items by barcode
                $this->inventoryLookup[intval($item->barcode)][] = [
                    'id' => $item->inventoryItemId,
                    'expiration' => $item->expirationDate,
                    'status' => $item->status,
                    'aisle' => $item->aisle,
                    'section' => $item->section,
                    'shelf' => $item->shelf,
                    'departmentId' => $item->departmentId,
                    'found' => false,
                ];

                $this->updateTrackedLocations($this->getLocKey($item->aisle, $item->section), $item->departmentId);
            }
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

        // Create any items from file that were not in existing inventory
        foreach ($this->fileItemsLookup as $barcode => $items) {
            foreach ($items as $aisle => $item) {
                $this->createNewItem($barcode, $item);
            }
        }

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

    public function setFileInventoryItem($barcode, $aisle, $section, $shelf, $description, $size)
    {
        // Sort by barcode, then include only one item per aisle
        $this->fileItemsLookup[intval($barcode)][$aisle] = [
            'aisle' => $aisle,
            'section' => $section,
            'shelf' => $shelf,
            'description' => $description,
            'size' => $size,
        ];
    }

    public function fileInventoryCount(): int
    {
        $count = count($this->fileItemsLookup);
        $this->import->outputContent("$count inventory items in file");
        return $count;
    }

    private function handleOneToOneMatch($barcode, $items)
    {
        $newItem = array_shift($items);
        $this->moveItem($this->inventoryLookup[$barcode][0], $newItem);

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

    private function moveItem($existingItem, $newItem)
    {
        if ($this->shouldMoveItem($existingItem, $newItem)) {
            $this->import->updateInventoryLocation(
                $existingItem['id'],
                $this->storeId,
                $existingItem['departmentId'],
                $newItem['aisle'],
                $newItem['section'],
                $newItem['shelf']
            );

            $this->updateTrackedLocations(
                $this->getLocKey($newItem['aisle'], $newItem['section']),
                $existingItem['departmentId']
            );
        } else {
            $this->import->recordStatic();
        }
    }

    // Do not move to skipped or identical locations
    private function shouldMoveItem($existing, $item): bool
    {
        if ($this->import->shouldSkipLocation($item['aisle'])) {
            return false;
        }

        return !($existing['aisle'] === $item['aisle']
            && $existing['section'] === $item['section']
            && $existing['shelf'] === $item['shelf']);
    }

    private function createNewItem($barcode, $item)
    {
        if ($this->import->isInSkipList($barcode)) {
            return;
        }

        if ($this->import->shouldSkipLocation($item['aisle'], $item['section'], $item['shelf'])) {
            return;
        }

        $locKey = $this->getLocKey($item['aisle'], $item['section']);

        // Do not add items in untracked locations
        if (!isset($this->trackedLocations[$locKey]) || ($this->trackedLocations[$locKey]['count'] <= $this->minItemsForTrackedLoc)) {
            $this->import->recordSkipped();
            return;
        }

        $item['departmentId'] = $this->trackedLocations[$locKey]['deptId'];
        $item['barcode'] = $barcode;

        $this->implementationScan($item);
    }

    private function implementationScan($item)
    {
        $product = $this->import->fetchProduct(BarcodeFixer::fixLength($item['barcode']));

        if (!$product->isExistingProduct) {
            $product->setDescription($this->parseDescription($item['description']));
            $product->setSize($this->parseSize($item['size']));
        }

        $this->import->implementationScan(
            $product,
            $this->storeId,
            $item['aisle'],
            $item['section'],
            $item['departmentId'],
            $item['shelf']
        );
    }

    private function discontinue($item)
    {
        $response = $this->proxy->writeInventoryDisco($this->companyId, $item['id']);
        $this->import->recordResponse($response, 'disco');
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
}
