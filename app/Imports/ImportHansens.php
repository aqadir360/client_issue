<?php

namespace App\Imports;

use App\Objects\Api;
use App\Objects\BarcodeFixer;
use App\Objects\Database;
use App\Objects\FtpManager;

class ImportHansens implements ImportInterface
{
    private $companyId = '61ef52da-c0e1-11e7-a59b-080027c30a85';
    private $stores;
    private $currentStoreId;
    private $path;
    private $skip;

    private $content = '';
    private $invalidStores = [];
    private $inventoryLookup = [];
    private $fileItemsLookup = [];
    private $trackedLocations = [];

    private $skipped = 0;
    private $newItems = 0;
    private $moved = 0;
    private $disco = 0;
    private $static = 0;
    private $newItemsError = 0;

    private $totalExistingItems = 0;
    private $maxAllowedDiscoPercent = 40;
    private $minItemsForTrackedLoc = 3;
    private $barcodesMissingDesc = [];

    /** @var Api */
    private $proxy;

    /** @var FtpManager */
    private $ftpManager;

    /** @var Database */
    private $db;

    public function __construct(Api $api, Database $database)
    {
        $this->proxy = $api;
        $this->db = $database;
        $this->path = storage_path('imports/hansens/');
        $this->ftpManager = new FtpManager('hansens/imports/weekly');

        $this->setStores();
        $this->skip = $this->ftpManager->getSkipList();
    }

    public function importUpdates()
    {
        $filesToImport = [];

        $files = $this->ftpManager->getRecentlyModifiedFiles();
        foreach ($files as $file) {
            if (strpos($file, 'zip') !== false) {
                continue;
            }
            $zipFile = $this->ftpManager->downloadFile($file);
            $filesToImport[] = $this->ftpManager->unzipFile($zipFile);
        }

        foreach ($filesToImport as $file) {
            $storeNum = substr(basename($file), 0, 4);
            $storeData = $this->storeNumToStoreId($storeNum);
            if ($storeData === false) {
                unlink($file);
                continue;
            }
            $this->currentStoreId = $storeData['id'];

            $this->setFileInventory($file);

            if (empty($this->fileItemsLookup)) {
                $this->outputContent("Skipping import for store: " . $storeData['name'] . ". Import file was empty");
                unlink($file);
                continue;
            }

            $this->setExistingInventory();
            $this->outputContent("Importing file '$file' for store: " . $storeData['name']);
            $this->outputContent("File contains " . count($this->fileItemsLookup) . " items and " . $this->totalExistingItems . " items currently exist in inventory");

            $this->compareStoreInventory();

            $this->outputContent($this->moved . " items moved");
            $this->outputContent($this->newItems . " items created");
            $this->outputContent($this->disco . " items discontinued");
            $this->outputContent($this->skipped . " items in untracked locations were skipped");
            $this->outputContent($this->static . " items were exact matches");
            $this->outputContent($this->newItemsError . " items could not be created");

            $this->moved = 0;
            $this->newItems = 0;
            $this->disco = 0;
            $this->skipped = 0;
            $this->static = 0;
            $this->newItemsError = 0;

            unlink($file);
        }

        if (!empty($this->invalidStores)) {
            $this->outputContent("Invalid store nums: " . implode(', ', $this->invalidStores));
        }

        if (!empty($this->barcodesMissingDesc)) {
            $this->outputContent("New products missing descriptions: ");

            foreach ($this->barcodesMissingDesc as $barcode) {
                $this->outputContent($barcode);
            }
        }

        $this->proxy->triggerUpdateCounts($this->companyId);
        $this->ftpManager->writeLastDate();
    }

    private function compareStoreInventory()
    {
        foreach ($this->fileItemsLookup as $barcode => $items) {
            if (isset($this->inventoryLookup[$barcode])) {
                if (count($items) === 1 && count($this->inventoryLookup[$barcode]) === 1) {
                    $this->handleOneToOneMatch($barcode, $items);
                } else {
                    $this->handleUnequalMatch($barcode, $items);
                }
            }
        }

        # now create any items that were not in inventoryLookup
        foreach ($this->fileItemsLookup as $barcode => $items) {
            foreach ($items as $aisle => $item) {
                if ($aisle == '999' || $aisle == '99') {
                    continue;
                }
                $locKey = $this->getLocKey($item);
                $this->createNewItem($barcode, $item, $locKey);
            }
        }

        // skip and email warning if over maxAllowedDiscoPercent
        $discoItems = [];
        foreach ($this->inventoryLookup as $items) {
            foreach ($items as $item) {
                if (!$item['found']) {
                    $discoItems[] = $item;
                }
            }
        }

        $discoPercent = $this->getPercentage(count($discoItems), $this->totalExistingItems);
        $this->outputContent("Disco percent: {$discoPercent}% ");
        if ($discoPercent > $this->maxAllowedDiscoPercent) {
            $this->outputContent("Skipping attempt to discontinue {$discoPercent}% of entire inventory.");

        } else {
            foreach ($discoItems as $item) {
                $this->discontinue($item);
            }
        }
        return true;
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
            $response = $this->proxy->updateInventoryLocation(
                $existingItem['id'],
                $this->currentStoreId,
                $existingItem['departmentId'],
                $newItem['aisle'],
                $newItem['section'],
                $newItem['shelf']
            );

            if (!$this->proxy->validResponse($response)) {
                $this->outputContent("UpdateInventoryLocation Failed! " . $response['message']);
            } else {
                $this->moved++;
                $this->updateTrackedLocations(
                    $this->getLocKey($newItem),
                    $existingItem['departmentId']
                );
            }
        } else {
            $this->static++;
        }
    }

    // Do not move to skipped or identical locations
    private function shouldMoveItem($existing, $item): bool
    {
        if ($item['aisle'] == '999' || $item['aisle'] == '99') {
            return false;
        }

        return !($existing['aisle'] === $item['aisle']
            && $existing['section'] === $item['section']
            && $existing['shelf'] === $item['shelf']);
    }

    private function createNewItem($barcode, $item, $locKey): bool
    {
        // Do not add skip list items
        if (isset($this->skip[intval($barcode)])) {
            return false;
        }

        // Do not add items in untracked locations
        if (!isset($this->trackedLocations[$locKey])) {
            $this->skipped++;
            return false;
        }

        if ($this->trackedLocations[$locKey] <= $this->minItemsForTrackedLoc) {
            $this->outputContent("$barcode added at low inventory location");
        }

        $item['departmentId'] = $this->trackedLocations[$locKey]['deptId'];
        $item['barcode'] = $barcode;

        return $this->implementationScan($item);
    }

    private function implementationScan($item): bool
    {
        $response = $this->proxy->implementationScan(
            $item['barcode'],
            $this->currentStoreId,
            $item['aisle'],
            $item['section'],
            $item['departmentId'],
            $this->parseDescription($item['description']),
            $this->parseSize($item['size']),
            $item['shelf']
        );

        if ($this->proxy->validResponse($response)) {
            $this->newItems++;
            return true;
        }

        $this->newItemsError++;
        if (strpos($response['message'], 'Missing product description for') !== false) {
            $this->barcodesMissingDesc[intval($item['barcode'])] = $item['barcode'];
        } else {
            $this->outputContent("ImplementationScan Failed! " . $response['message']);
        }

        return false;
    }

    private function discontinue($item): bool
    {
        $response = $this->proxy->updateInventoryDisco(
            $item['id'],
            $item['expiration'],
            $item['status']
        );

        if ($this->proxy->validResponse($response)) {
            $this->disco++;
            return true;
        }

        $this->outputContent("Discontinue Failed for " . $item['id'] . $response['message']);
        return false;
    }

    private function setStores()
    {
        $response = $this->proxy->fetchAllStores($this->companyId);

        foreach ($response['stores'] as $store) {
            if (!empty($store['storeNum'])) {
                $this->stores[$store['storeNum']] = [
                    'id' => $store['storeId'],
                    'name' => $store['name'],
                ];
            }
        }
    }

    private function storeNumToStoreId($input)
    {
        if (isset($this->stores[$input])) {
            return $this->stores[$input];
        } else {
            if (!isset($this->invalidStores[$input])) {
                $this->invalidStores[$input] = $input;
            }
            return false;
        }
    }

    private function setExistingInventory()
    {
        $this->inventoryLookup = [];
        $this->trackedLocations = [];
        $this->totalExistingItems = 0;

        $response = $this->proxy->fetchAllInventory(
            $this->companyId,
            $this->currentStoreId
        );

        if ($this->proxy->validResponse($response)) {
            foreach ($response['data']['items'] as $item) {
                $this->totalExistingItems++;

                $add = [
                    'id' => $item['inventoryItemId'],
                    'expiration' => $item['expirationDate'],
                    'status' => $item['status'],
                    'aisle' => $item['aisle'],
                    'section' => $item['section'],
                    'shelf' => $item['shelf'],
                    'departmentId' => $item['departmentId'],
                    'found' => false,
                ];

                // Sort all items by barcode
                if (isset($this->inventoryLookup[$item['barcode']])) {
                    $this->inventoryLookup[$item['barcode']][] = $add;
                } else {
                    $this->inventoryLookup[$item['barcode']] = [$add];
                }

                $this->updateTrackedLocations($this->getLocKey($item), $item['departmentId']);
            }
        }
    }

    private function setFileInventory($file)
    {
        $this->fileItemsLookup = [];

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                if ($data[0] === "store_id") {
                    continue;
                }

                $upc = '0' . BarcodeFixer::fixUpc(trim($data[4]));
                $loc = $this->parseLocation(trim($data[13]));

                // Sort by barcode, then include only one item per aisle
                $this->fileItemsLookup[$upc][$loc['aisle']] = [
                    'aisle' => $loc['aisle'],
                    'section' => $loc['section'],
                    'shelf' => $loc['shelf'],
                    'description' => trim($data[6]),
                    'size' => trim($data[7]),
                ];
            }

            fclose($handle);
        }
    }

    private function getLocKey($item)
    {
        return $item['aisle'] . '-' . $item['section'];
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

    private function parseLocation(string $location)
    {
        return [
            'aisle' => substr($location, 0, 2),
            'section' => substr($location, 2, 3),
            'shelf' => substr($location, 5, 2),
        ];
    }

    private function outputContent($msg)
    {
        echo $msg . PHP_EOL;
        $this->content .= "<p>" . $msg . "</p> ";
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
