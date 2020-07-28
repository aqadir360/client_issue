<?php

namespace App\Imports;

use App\Models\Location;
use App\Models\Product;
use App\Objects\Api;
use App\Objects\BarcodeFixer;
use App\Objects\Database;
use App\Objects\FtpManager;
use App\Objects\ImportManager;

// Lunds Inventory and Metrics Import
// Expects Aisle Change, Discontinued, All Items (metrics), and Exclude (skip) files twice weekly
class ImportLunds implements ImportInterface
{
    private $companyId = '0ba8c4a0-9e50-11e7-b25f-f23c917b0c87';

    /** @var ImportManager */
    private $import;

    /** @var Api */
    private $proxy;

    /** @var FtpManager */
    private $ftpManager;

    public function __construct(Api $api, Database $database)
    {
        $this->proxy = $api;
        $this->ftpManager = new FtpManager('lunds/imports');
        $this->import = new ImportManager($database, $this->companyId);
        $this->import->setSkipList();
    }

    public function importUpdates()
    {
        $importList = [];
        $discoList = [];
        $metricsList = [];
        $skipList = [];

        $files = $this->ftpManager->getRecentlyModifiedFiles();
        foreach ($files as $file) {
            if (strpos($file, 'Aisle_Changes') !== false) {
                $importList[] = $this->ftpManager->downloadFile($file);
            } elseif (strpos($file, 'Discontinued') !== false) {
                $discoList[] = $this->ftpManager->downloadFile($file);
            } elseif (strpos($file, 'All_Items') !== false) {
                $metricsList[] = $this->ftpManager->downloadFile($file);
            } elseif (strpos($file, 'Exclude') !== false && strpos($file, 'csv') !== false) {
                $skipList[] = $this->ftpManager->downloadFile($file);
            }
        }

        foreach ($skipList as $filePath) {
            $this->addToSkipList($filePath);
        }

        foreach ($importList as $filePath) {
            $this->importActiveFile($filePath);
        }

        foreach ($discoList as $filePath) {
            $this->importDiscoFile($filePath);
        }

        foreach ($metricsList as $filePath) {
            $this->importMetricsFile($filePath);
        }

        $this->proxy->triggerUpdateCounts($this->companyId);
        $this->ftpManager->writeLastDate();
        $this->import->completeImport();
    }

    private function importDiscoFile($file)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                $this->import->recordRow();

                $storeId = $this->import->storeNumToStoreId($data[0]);
                if ($storeId === false) {
                    continue;
                }

                $upc = BarcodeFixer::fixLength($data[1]);
                if ($this->import->isInvalidBarcode($upc, $data[1])) {
                    continue;
                }

                $this->discontinue($upc, $storeId);
            }
        }

        $this->import->completeFile();
        fclose($handle);
    }

    // Expects File Format:
    // [0] STORE_ID
    // [1] UPC_EAN
    // [2] DESCRIPTION
    // [3] SELL_SIZE
    // [4] LOCATION
    // [5] Tag Quantity
    // [6] DateSTR
    // [7] Department
    // [8] Vendor_Name
    // [9] Item_Status
    // [10] Unit_Cost
    // [11] Retail
    // [12] AVERAGE_MOVEMENT
    private function importMetricsFile($file)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                $storeId = $this->import->storeNumToStoreId($data[0]);
                if ($storeId === false) {
                    continue;
                }

                $upc = BarcodeFixer::fixLength($data[1]);
                if ($this->import->isInvalidBarcode($upc, $data[1])) {
                    continue;
                }

                $success = $this->persistMetric($upc, $storeId, $data);
                if ($success) {
                    $this->import->recordMetric($success);
                } else {
                    $this->import->currentFile->skipped;
                }

                $this->proxy->createVendor($upc, trim($data[8]), $this->companyId);
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }

    private function importActiveFile($file)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                $this->import->recordRow();

                // skip items with zero or empty tag quantity
                if (intval($data[5]) <= 0) {
                    $this->import->currentFile->skipped++;
                    continue;
                }

                $storeId = $this->import->storeNumToStoreId($data[0]);
                if ($storeId === false) {
                    continue;
                }

                $upc = BarcodeFixer::fixLength($data[1]);
                if ($this->import->isInvalidBarcode($upc, $data[1])) {
                    continue;
                }

                if ($this->import->isInSkipList($upc)) {
                    $this->import->currentFile->skipList++;
                    continue;
                }

                $action = trim($data[9]);
                if ($action === 'To Discontinue' || $action === 'Discontinued') {
                    $this->discontinue($upc, $storeId);
                } else {
                    $departmentId = $this->import->getDepartmentId($data[7]);
                    if ($departmentId === false) {
                        continue;
                    }

                    $expires = (string)date('Y-m-d 00:00:00', strtotime($data[6]));
                    if (empty($expires)) {
                        $this->import->currentFile->recordError('Invalid Expiration Date', $data[6]);
                        continue;
                    }

                    $location = $this->parseLocation($data[4]);
                    if (intval($location->aisle) === 127) {
                        // move these items to the pet department
                        $departmentId = $this->import->getDepartmentId('pet');
                    }

                    $product = $this->import->fetchProduct($upc, $storeId);
                    if ($product->isExistingProduct) {
                        $this->handleExistingProduct($product, $storeId, $departmentId, $location);
                    } else {
                        $product->setDescription(($data[2]));
                        $product->setSize(($data[3]));
                        $this->createNewProductAndItem($product, $storeId, $departmentId, $location);
                    }

                    $this->proxy->createVendor($product->barcode, trim($data[8]), $this->companyId);
                    $this->persistMetric($product->barcode, $storeId, $data);
                }
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }

    private function createNewProductAndItem(
        Product $product,
        string $storeId,
        string $departmentId,
        Location $location
    ) {
        $response = $this->proxy->implementationScan(
            $product,
            $storeId,
            $location->aisle,
            $location->section,
            $departmentId
        );
        $this->import->recordAdd($response);
    }

    private function handleExistingProduct(
        Product $product,
        string $storeId,
        string $departmentId,
        Location $location
    ) {
        $item = $product->getMatchingInventoryItem($location, $departmentId);

        if ($item !== null) {
            if ($this->needToMoveItem($item, $location)) {
                $this->moveInventory($item, $storeId, $location);
            } else {
                $this->import->currentFile->skipped++;
            }
        } else {
            $response = $this->proxy->implementationScan(
                $product,
                $storeId,
                $location->aisle,
                $location->section,
                $departmentId
            );
            $this->import->recordAdd($response);
        }
    }

    private function needToMoveItem($item, Location $location)
    {
        return !($item->aisle == $location->aisle && $item->section == $location->section);
    }

    private function discontinue($upc, $storeId)
    {
        $response = $this->proxy->discontinueProductByBarcode($storeId, $upc);
        $this->import->recordDisco($response);
    }

    private function moveInventory(
        $item,
        string $storeId,
        Location $location
    ) {
        $response = $this->proxy->updateInventoryLocation(
            $item->inventoryItemId,
            $storeId,
            $item->departmentId, // do not overwrite the existing department
            $location->aisle,
            $location->section
        );
        $this->import->recordMove($response);
    }

    private function persistMetric($upc, $storeId, $row)
    {
        if (count($row) >= 12) {
            $cost = floatval($row[10]);
            $retail = floatval($row[11]);
            // sending weekly movement
            $movement = round(floatval($row[12]) / 7, 4);

            $product = $this->import->fetchProduct($upc);
            if ($product->isExistingProduct === false) {
                return false;
            }

            return $this->import->persistMetric(
                $storeId,
                $product->productId,
                $this->import->convertFloatToInt($cost),
                $this->import->convertFloatToInt($retail),
                $this->import->convertFloatToInt($movement)
            );
        }

        return false;
    }

    private function parseLocation(string $input): Location
    {
        $location = new Location();

        if (strlen($input) > 0) {
            $exploded = explode('-', $input);
            if (count($exploded) > 1) {
                // preserve leading zeros
                $location->aisle = trim($exploded[0]);
                $location->section = trim($exploded[1]);
                $location->valid = true;
            }
        }

        return $location;
    }

    private function addToSkipList($file)
    {
        $this->import->startNewFile($file);

        // adds any new upcs to database
        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                $upc = trim($data[0]);
                if ($upc != 'UPCs to Exclude') {
                    $this->import->recordRow();
                    if ($this->import->addToSkipList($upc)) {
                        $this->import->currentFile->adds++;
                    } else {
                        $this->import->currentFile->skipped++;
                    }
                }
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }
}
