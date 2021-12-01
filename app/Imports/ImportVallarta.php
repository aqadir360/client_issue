<?php

namespace App\Imports;

use App\Imports\Settings\VallartaSettings;
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

    /** @var VallartaSettings */
    private $settings;

    public function __construct(ImportManager $importManager)
    {
        $this->import = $importManager;
        $this->settings = new VallartaSettings();
        $this->import->setCategories();
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
                    $this->import->writeFileOutput($data, "Skip: Invalid Store");
                    continue;
                }

                $barcode = $this->fixBarcode(trim($data[2]));
                if ($this->import->isInvalidBarcode($barcode, $data[2])) {
                    $this->import->writeFileOutput($data, "Skip: Invalid Barcode");
                    continue;
                }

                switch (trim($data[0])) {
                    case 'disco':
                        // Skipping discontinues due to incorrect timing
                        $this->import->recordSkipped();
                        $this->import->writeFileOutput($data, "Skip: disco");
                        break;
                    case 'move':
                        $this->handleMove($data, $barcode, $storeId);
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

    private function handleAdd(array $data, string $barcode, string $storeId)
    {
        $department = trim($data[5]);
        $category = trim($data[9]);

        $location = new Location(trim($data[3]), trim($data[4]));
        if ($this->settings->shouldSkipLocation($location)) {
            $this->import->writeFileOutput($data, "Skip Add: Invalid Location");
            $this->import->recordSkipped();
            return;
        }

        $product = $this->import->fetchProduct($barcode, $storeId);
        if ($product->hasInventory()) {
            $this->import->recordSkipped();
            $this->import->writeFileOutput($data, "Static Add: Existing Inventory");
            return;
        }

        if ($product->isExistingProduct) {
            $this->import->recordCategory($product, $department, $category);
        } else {
            $product->setDescription($data[6]);
            $product->setSize($data[7]);
        }

        $departmentId = $this->import->getDepartmentId($department, $category);
        if ($departmentId === false) {
            $this->import->writeFileOutput($data, "Skip Add: Invalid Department");
            return;
        }

        $this->import->writeFileOutput($data, "Success: Creating Inventory");
        $this->import->implementationScan(
            $product,
            $storeId,
            $location->aisle,
            $location->section,
            $departmentId
        );
    }

    private function handleMove(array $data, string $barcode, string $storeId)
    {
        $department = trim($data[7]);
        $category = trim($data[9]);

        $product = $this->import->fetchProduct($barcode, $storeId);
        if ($product->isExistingProduct) {
            $this->import->recordCategory($product, $department, $category);
        } else {
            // Moves do not include product information
            $this->import->recordSkipped();
            $this->import->writeFileOutput($data, "Skip Move: New Product");
            return;
        }

        $deptId = $this->import->getDepartmentId(strtolower($department), strtolower($category));
        if ($deptId === false) {
            $this->import->writeFileOutput($data, "Skip Move: Invalid Department");
            return;
        }

        $location = new Location(trim($data[5]), trim($data[6]));

        if ($product->hasInventory()) {
            $item = $product->getMatchingInventoryItem($location, $deptId);

            if ($item !== null) {
                // Disco takes priority over skip invalid location
                if ($this->settings->shouldDisco($location)) {
                    $this->import->discontinueInventory($item->inventory_item_id);
                    $this->import->writeFileOutput($data, "Success: Discontinued");
                    return;
                }

                if ($this->settings->shouldSkipLocation($location)) {
                    $this->import->recordSkipped();
                    $this->import->writeFileOutput($data, "Skip Move: Invalid Location");
                    return;
                }

                $this->moveInventory($data, $item, $storeId, $deptId, $location);
                return;
            }
        }

        if ($this->settings->shouldSkipLocation($location)) {
            $this->import->recordSkipped();
            $this->import->writeFileOutput($data, "Skip Move: Invalid Location");
            return;
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

    private function moveInventory(array $data, $item, string $storeId, string $deptId, Location $location)
    {
        if ($item->aisle == $location->aisle) {
            if ($item->section == $location->section) {
                $this->import->recordStatic();
                $this->import->writeFileOutput($data, "Static Move");
                return;
            }

            if (empty($location->section) && !empty($item->section)) {
                // Do not clear existing section information
                $this->import->recordStatic();
                $this->import->writeFileOutput($data, "Static Move: Clearing location");
                return;
            }
        }

        $this->import->writeFileOutput($data, "Success: Updated Location");
        $this->import->updateInventoryLocation(
            $item->inventory_item_id,
            $storeId,
            $deptId,
            $location->aisle,
            $location->section
        );
    }

    private function importMetricsFile(string $file)
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
                    $this->import->writeFileOutput($data, "Skip: Invalid Barcode");
                    continue;
                }

                $product = $this->import->fetchProduct($barcode);
                if ($product->isExistingProduct === false) {
                    $this->import->writeFileOutput($data, "Skip: New Product");
                    $this->import->recordSkipped();
                    continue;
                }

                $this->import->persistMetric(
                    $storeId,
                    $product,
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

    private function fixBarcode(string $upc): string
    {
        while (strlen($upc) > 0 && $upc[0] === '0') {
            $upc = substr($upc, 1);
        }

        $output = str_pad(ltrim($upc, '0'), 12, '0', STR_PAD_LEFT);

        return $output . BarcodeFixer::calculateMod10Checksum($output);
    }
}
