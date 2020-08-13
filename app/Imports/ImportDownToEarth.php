<?php

namespace App\Imports;

use App\Models\Location;
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

    /* Update file format:
         [0] - Store Number
         [1] - Barcode
         [2] - Aisle
         [3] - Section
         [4] - Shelf
         [5] - Department
         [6] - Description
         [7] - Size
         [8] - Daily Movement
         [9] - Retail
         [10] - Cost */
    private function importUpdateFile($file)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                if (!$this->import->recordRow()) {
                    break;
                }

                if (strtolower($data[0]) !== 'add') {
                    $this->import->recordSkipped();
                    continue;
                }

                $storeId = $this->import->storeNumToStoreId(intval($data[1]));
                if ($storeId === false) {
                    continue;
                }

                $departmentId = $this->import->getDepartmentId(trim($data[6]));
                if ($departmentId === false) {
                    continue;
                }

                $barcode = trim($data[2]);
                if ($this->import->isInvalidBarcode($barcode, $data[2])) {
                    continue;
                }

                $location = $this->parseLocation($data);
                if (!$location->valid) {
                    $this->import->recordSkipped();
                    continue;
                }

                $product = $this->import->fetchProduct($barcode, $storeId);

                // Do not add items with existing inventory
                if ($product->isExistingProduct && $product->hasInventory()) {
                    $this->import->recordSkipped();
                    continue;
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

                // TODO: new products should be added for immediate review
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }

    private function importMetricsFile($file)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                if (!$this->import->recordRow()) {
                    break;
                }

                $upc = trim($data[1]);
                if ($this->import->isInvalidBarcode($upc, $upc)) {
                    continue;
                }

                $storeId = $this->import->storeNumToStoreId(intval($data[0]));
                if ($storeId === false) {
                    continue;
                }

                $product = $this->import->fetchProduct($upc);
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

    private function parseLocation($data): Location
    {
        $location = new Location(trim($data[3]));

        if (!empty($location->aisle)) {
            $location->section = trim($data[5]);

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
}
