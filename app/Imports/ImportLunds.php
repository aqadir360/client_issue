<?php

namespace App\Imports;

use App\Models\Location;
use App\Models\Product;
use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;

// Lunds Inventory and Metrics Import
// Expects Aisle Change, Discontinued, All Items (metrics), and Exclude (skip) files twice weekly
class ImportLunds implements ImportInterface
{
    /** @var ImportManager */
    private $import;

    public function __construct(ImportManager $importManager)
    {
        $this->import = $importManager;
        $this->import->setSkipList();
    }

    public function importUpdates()
    {
        $importList = $this->import->downloadFilesByName('Aisle_Changes');
        $discoList = $this->import->downloadFilesByName('Discontinued');
        $metricsList = $this->import->downloadFilesByName('All_Items');
        $skipList = $this->import->downloadFilesByName('Exclude');

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

        $this->import->completeImport();
    }

    private function importDiscoFile($file)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                if (trim($data[0]) === 'STORE_ID') {
                    continue;
                }

                if (!$this->import->recordRow()) {
                    break;
                }

                $storeId = $this->import->storeNumToStoreId($data[0]);
                if ($storeId === false) {
                    continue;
                }

                $upc = BarcodeFixer::fixLength($data[1]);
                if ($this->import->isInvalidBarcode($upc, $data[1])) {
                    continue;
                }

                $this->import->discontinueProductByBarcode($storeId, $upc);
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
                if (trim($data[0]) === 'STORE_ID') {
                    continue;
                }

                if (!$this->import->recordRow()) {
                    break;
                }

                $storeId = $this->import->storeNumToStoreId($data[0]);
                if ($storeId === false) {
                    continue;
                }

                $upc = BarcodeFixer::fixLength($data[1]);
                if ($this->import->isInvalidBarcode($upc, $data[1])) {
                    continue;
                }

                $this->persistMetric($upc, $storeId, $data);
                $this->import->createVendor($upc, trim($data[8]));
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
                if ($data[0] === 'STORE_ID') {
                    continue;
                }

                if (!$this->import->recordRow()) {
                    break;
                }

                // skip items with zero or empty tag quantity
                if (intval($data[5]) <= 0) {
                    $this->import->recordSkipped();
                    continue;
                }

                $storeId = $this->import->storeNumToStoreId($data[0]);
                if ($storeId === false) {
                    continue;
                }

                $inputUpc = trim($data[1]);
                $upc = BarcodeFixer::fixLength($inputUpc);
                if ($this->import->isInvalidBarcode($upc, $inputUpc)) {
                    continue;
                }

                if ($this->import->isInSkipList($upc) || $this->import->isInSkipList($inputUpc)) {
                    continue;
                }

                $action = trim($data[9]);
                if ($action === 'To Discontinue' || $action === 'Discontinued') {
                    $this->import->discontinueProductByBarcode($storeId, $upc);
                } else {
                    $departmentId = $this->import->getDepartmentId($data[7]);
                    if ($departmentId === false) {
                        continue;
                    }

                    $expires = (string)date('Y-m-d 00:00:00', strtotime($data[6]));
                    if (empty($expires)) {
                        $this->import->recordFileError('Invalid Expiration Date', $data[6]);
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

                    $this->import->createVendor($upc, trim($data[8]));

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
        $this->import->implementationScan(
            $product,
            $storeId,
            $location->aisle,
            $location->section,
            $departmentId
        );
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
                $this->import->updateInventoryLocation(
                    $item->inventory_item_id,
                    $storeId,
                    $item->department_id, // do not overwrite the existing department
                    $location->aisle,
                    $location->section
                );
            } else {
                $this->import->recordStatic();
            }
        } else {
            $this->import->implementationScan(
                $product,
                $storeId,
                $location->aisle,
                $location->section,
                $departmentId
            );
        }
    }

    private function needToMoveItem($item, Location $location)
    {
        return !($item->aisle == $location->aisle && $item->section == $location->section);
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
                return;
            }

            $this->import->persistMetric(
                $storeId,
                $product->productId,
                $this->import->convertFloatToInt($cost),
                $this->import->convertFloatToInt($retail),
                $this->import->convertFloatToInt($movement)
            );
        }
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
        if (strpos($file, 'csv') === false) {
            return;
        }

        $this->import->startNewFile($file);

        // adds any new upcs to database
        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                $upc = trim($data[0]);
                if ($upc != 'UPCs to Exclude') {
                    if (!$this->import->recordRow()) {
                        break;
                    }
                    if ($this->import->addToSkipList($upc)) {
                        $this->import->recordAdd();
                    } else {
                        $this->import->recordSkipped();
                    }
                }
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }
}
