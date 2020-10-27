<?php

namespace App\Imports;

use App\Models\Location;
use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;

// Down To Earth Inventory and Metrics Import
class ImportDownToEarth implements ImportInterface
{
    /** @var ImportManager */
    private $import;

    public function __construct(ImportManager $importManager)
    {
        $this->import = $importManager;
    }

    public function importUpdates()
    {
        $updateList = $this->import->downloadFilesByName('update_');
        $metricsList = $this->import->downloadFilesByName('PRODUCT_FILE_');

        foreach ($updateList as $file) {
            $this->importUpdateFile($file);
        }

        foreach ($metricsList as $file) {
            $this->importMetricsFile($file);
        }

        $this->import->completeImport();
    }

    private function importUpdateFile($file)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                if (!$this->import->recordRow()) {
                    break;
                }

                $storeId = $this->import->storeNumToStoreId(intval($data[1]));
                if ($storeId === false) {
                    continue;
                }

                $barcode = $this->fixBarcode(trim($data[2]));
                if ($this->import->isInvalidBarcode($barcode, $data[2])) {
                    continue;
                }

                $action = strtolower($data[0]);
                switch ($action) {
                    case 'disco':
                        $this->import->discontinueProductByBarcode($storeId, $barcode);
                        break;
                    case 'add':
                        $this->addInventory($storeId, $barcode, $data);
                        break;
                    case 'move':
                        $this->moveInventory($storeId, $barcode, $data);
                        break;
                    default:
                        $this->import->recordFileLineError('ERROR', 'Unknown Action ' . $action);
                }
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }

    private function addInventory($storeId, $barcode, $data)
    {
        $departmentId = $this->import->getDepartmentId(trim($data[6]));
        if ($departmentId === false) {
            return;
        }

        $location = $this->parseLocation($data[3], $data[5]);
        if (!$location->valid) {
            $this->import->recordSkipped();
            return;
        }

        $product = $this->import->fetchProduct($barcode, $storeId);

        // Do not add items with existing inventory
        if ($product->isExistingProduct && $product->hasInventory()) {
            $this->import->recordSkipped();
            return;
        }

        $product->setDescription($data[7]);
        $product->setSize($data[8]);

        $this->import->implementationScan(
            $product,
            $storeId,
            $location->aisle,
            $location->section,
            $departmentId
        );
    }

    private function moveInventory($storeId, $barcode, $data)
    {
        $existingLocation = $this->parseLocation($data[3], $data[4]);
        $newLocation = $this->parseLocation($data[5], $data[6]);

        if (!$newLocation->valid) {
            $this->import->recordFileLineError('ERROR', 'Location ' . $data[5] . " " . $data[6]);
            return;
        }

        $product = $this->import->fetchProduct($barcode, $storeId);
        if (!($product->isExistingProduct && $product->hasInventory())) {
            $this->import->recordSkipped();
            return;
        }

        $item = $product->getMatchingInventoryItem($existingLocation, '');
        if ($item === null) {
            $this->import->recordSkipped();
            return;
        }

        if ($this->needToMoveItem($item, $newLocation)) {
            $this->import->updateInventoryLocation(
                $item->inventory_item_id,
                $storeId,
                $item->department_id,
                $newLocation->aisle,
                $newLocation->section
            );
        } else {
            $this->import->recordStatic();
        }
    }

    private function needToMoveItem($item, Location $location)
    {
        return !($item->aisle == $location->aisle && $item->section == $location->section);
    }

    private function importMetricsFile($file)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                if (!$this->import->recordRow()) {
                    break;
                }

                $barcode = $this->fixBarcode(trim($data[1]));
                if ($this->import->isInvalidBarcode($barcode, $data[1])) {
                    continue;
                }

                $storeId = $this->import->storeNumToStoreId(intval($data[0]));
                if ($storeId === false) {
                    continue;
                }

                $product = $this->import->fetchProduct($barcode);
                if ($product->isExistingProduct === false) {
                    $this->import->recordSkipped();
                    continue;
                }

                $this->import->persistMetric(
                    $storeId,
                    $product->productId,
                    $this->import->convertFloatToInt($this->import->parsePositiveFloat($data[10])),
                    $this->import->convertFloatToInt($this->import->parsePositiveFloat($data[9])),
                    $this->import->convertFloatToInt($this->import->parsePositiveFloat($data[8]))
                );
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }

    private function parseLocation($aisle, $section): Location
    {
        $location = new Location(trim($aisle));

        if (!empty($location->aisle)) {
            $location->section = trim($section);

            // Skipping seasonal
            if ($location->section === 'GHOLIDAY') {
                return $location;
            }

            // Remove Aisle from Section if duplicated
            if (strpos($location->section, $location->aisle) !== false) {
                $location->section = substr($location->section, strlen($location->aisle));
            }

            $location->valid = true;
        }

        return $location;
    }

    private function fixBarcode($input)
    {
        if (!is_numeric($input)) {
            $this->import->recordFileLineError('Error', "Invalid Barcode $input");
            return false;
        }

        $upc = '0' . BarcodeFixer::fixUpc($input);

        if (!BarcodeFixer::isValid($upc)) {
            $upc = BarcodeFixer::fixLength($input);
        }

        return $upc;
    }
}
