<?php

namespace App\Imports;

use App\Models\Location;
use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;

// Vallarta Inventory and Metrics Import
// Expects update and metrics files weekly
// Adds all products with unknown location and grocery department
class ImportVallarta implements ImportInterface
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
        $metricsList = $this->import->downloadFilesByName('metrics_');

        foreach ($updateList as $filePath) {
            $this->importActiveFile($filePath);
        }

        foreach ($metricsList as $filePath) {
            $this->importMetricsFile($filePath);
        }

        $this->import->completeImport();
    }

    private function importActiveFile($file)
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

                switch (trim($data[0])) {
                    case 'disco':
                        // Skipping discontinues due to incorrect timing
                        $this->import->recordSkipped();
                        break;
                    case 'move':
                        $location = new Location(trim($data[5]), trim($data[6]));
                        $this->handleMove($barcode, $storeId, trim($data[7]), $location);
                        break;
                    case 'add':
                        $this->handleAdd($data, $barcode, $storeId);
                        break;
                }
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }

    private function handleAdd($data, $barcode, $storeId)
    {
        $departmentId = $this->import->getDepartmentId(trim(strtolower($data[5])));
        if ($departmentId === false) {
            return;
        }

        $product = $this->import->fetchProduct($barcode, $storeId);
        if ($product->hasInventory()) {
            $this->import->recordSkipped();
            return;
        }

        if ($product->isExistingProduct === false) {
            $product->setDescription($data[6]);
            $product->setSize($data[7]);
        }

        $this->import->implementationScan(
            $product,
            $storeId,
            trim($data[3]),
            trim($data[4]),
            $departmentId
        );
    }

    private function handleMove($barcode, $storeId, $department, Location $location)
    {
        $deptId = $this->import->getDepartmentId(trim(strtolower($department)));
        if ($deptId === false) {
            return;
        }

        if ($this->shouldSkip($location->aisle, $location->section)) {
            $this->import->recordSkipped();
            return;
        }

        $product = $this->import->fetchProduct($barcode, $storeId);
        if ($product->isExistingProduct === false) {
            // Moves do not include product information
            $this->import->recordSkipped();
            return;
        }

        if ($product->hasInventory()) {
            $item = $product->getMatchingInventoryItem($location, $deptId);

            if ($item !== null) {
                $this->import->updateInventoryLocation(
                    $item->inventory_item_id,
                    $storeId,
                    $deptId,
                    $location->aisle,
                    $location->section
                );
                return;
            }
        }

        // Adding as new any moves that do not exist in inventory
        $this->import->implementationScan(
            $product,
            $storeId,
            $location->aisle,
            $location->section,
            $deptId
        );
    }

    private function importMetricsFile($file)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                if (!$this->import->recordRow()) {
                    break;
                }

                $storeId = $this->import->storeNumToStoreId(intval($data[0]));
                if ($storeId === false) {
                    continue;
                }

                $barcode = $this->fixBarcode(trim($data[1]));
                if ($this->import->isInvalidBarcode($barcode, $data[1])) {
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
                    $this->import->convertFloatToInt(floatval($data[4])),
                    $this->import->convertFloatToInt(floatval($data[3])),
                    $this->import->convertFloatToInt(floatval($data[2])),
                    true
                );
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }

    private function shouldSkip($aisle, $section): bool
    {
        if (empty($aisle) || $aisle == 'OUT' || $aisle == 'zzz' || $aisle == 'XXX' || $aisle == '*80') {
            return true;
        }

        if ($aisle == '000' && ($section == '000' || empty($section))) {
            return true;
        }

        return false;
    }

    // Always add check digit
    private function fixBarcode(string $input)
    {
        $upc = str_pad(ltrim($input, '0'), 11, '0', STR_PAD_LEFT);
        return $upc . BarcodeFixer::calculateMod10Checksum($upc);
    }
}
