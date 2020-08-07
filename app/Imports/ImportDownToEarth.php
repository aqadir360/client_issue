<?php

namespace App\Imports;

use App\Models\Location;
use App\Objects\Api;
use App\Objects\Database;
use App\Objects\FtpManager;
use App\Objects\ImportManager;

// Down To Earth Inventory and Metrics Import
class ImportDownToEarth implements ImportInterface
{
    private $companyId = '5b4619fc-bc76-989c-53ed-510d0be8c7c4';

    /** @var ImportManager */
    private $import;

    /** @var FtpManager */
    private $ftpManager;

    /** @var Api */
    private $proxy;

    public function __construct(Api $api, Database $database)
    {
        $this->proxy = $api;
        $this->ftpManager = new FtpManager('downtoearth/imports');
        $this->import = new ImportManager($database, $this->companyId);
    }

    public function importUpdates()
    {
        $updateList = [];
        $metricsList = [];

        $files = $this->ftpManager->getRecentlyModifiedFiles();
        foreach ($files as $file) {
            if (strpos($file, 'update_') !== false) {
                $updateList[] = $this->ftpManager->downloadFile($file);
            } elseif (strpos($file, 'PRODUCT_FILE_') !== false) {
                $metricsList[] = $this->ftpManager->downloadFile($file);
            }
        }

        foreach ($updateList as $file) {
            $this->importUpdateFile($file);
        }

        foreach ($metricsList as $file) {
            $this->importMetricsFile($file);
        }

        $this->completeImport();
    }

    public function completeImport(string $error = '')
    {
        $this->proxy->triggerUpdateCounts($this->companyId);
        $this->ftpManager->writeLastDate();
        $this->import->completeImport($error);
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
                $this->import->recordRow();

                if (strtolower($data[0]) !== 'add') {
                    $this->import->currentFile->skipped++;
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
                    $this->import->currentFile->skipped++;
                    continue;
                }

                $product = $this->import->fetchProduct($barcode, $storeId);

                // Do not add items with existing inventory
                if ($product->isExistingProduct && $product->hasInventory()) {
                    $this->import->currentFile->skipped++;
                    continue;
                }

                $product->setDescription($data[7]);
                $product->setSize($data[8]);

                $response = $this->proxy->implementationScan(
                    $product,
                    $storeId,
                    $location->aisle,
                    $location->section,
                    $departmentId
                );

                $this->import->recordAdd($response);

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
                $this->import->recordRow();

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
                    $this->import->currentFile->skipped++;
                    return;
                }

                $success = $this->import->persistMetric(
                    $storeId,
                    $product->productId,
                    $this->import->convertFloatToInt($this->import->parsePositiveFloat($data[10])),
                    $this->import->convertFloatToInt($this->import->parsePositiveFloat($data[9])),
                    $this->import->convertFloatToInt($this->import->parsePositiveFloat($data[8]))
                );

                if ($success) {
                    $this->import->recordMetric($success);
                } else {
                    $this->import->currentFile->skipped++;
                }
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
