<?php

namespace App\Imports;

use App\Objects\Api;
use App\Objects\ImportFtpManager;
use App\Objects\ImportStatusOutput;

// Expects file format:
// [0] - Store Number
// [1] - Barcode
// [2] - Aisle
// [3] - Section
// [4] - Shelf
// [5] - Department
// [6] - Description
// [7] - Size
// [8] - Daily Movement
// [9] - Retail
// [10] - Cost
class ImportDownToEarth implements ImportInterface
{
    private $companyId = '5b4619fc-bc76-989c-53ed-510d0be8c7c4';
    private $path;

    /** @var ImportStatusOutput */
    private $importStatus;

    /** @var ImportFtpManager */
    private $ftpManager;

    /** @var Api */
    private $proxy;

    public function __construct(Api $api)
    {
        $this->proxy = $api;
        $this->path = storage_path('imports/dte/');
        $this->ftpManager = new ImportFtpManager('imports/dte/', 'downtoearth/imports');
        $this->importStatus = new ImportStatusOutput($this->companyId, 'Down To Earth');
        $this->importStatus->setStores($this->proxy);
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

        $this->importStatus->outputResults();
        $this->ftpManager->writeLastDate();
    }

    private function importUpdateFile($file)
    {
        $this->importStatus->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                $this->importStatus->recordRow();

                if (strtolower($data[0]) !== 'add') {
                    $this->importStatus->currentFile->skipped++;
                    continue;
                }

                $storeId = $this->importStatus->storeNumToStoreId(intval($data[1]));
                if ($storeId === false) {
                    continue;
                }

                $departmentId = $this->getDepartmentId(trim($data[6]));
                if ($departmentId === false) {
                    $this->importStatus->currentFile->skipped++;
                    continue;
                }

                $barcode = trim($data[2]);
                if ($this->importStatus->isInvalidBarcode($barcode)) {
                    continue;
                }

                $location = $this->parseLocation($data);
                if ($location === false) {
                    $this->importStatus->currentFile->skipped++;
                    continue;
                }

                $product = $this->fetchProduct($barcode, $storeId);
                if ($product && count($product['inventory']) > 0) {
                    // Do not add items with existing inventory
                    $this->importStatus->currentFile->skipped++;
                    continue;
                }

                $response = $this->proxy->implementationScan(
                    $barcode,
                    $storeId,
                    $location['aisle'],
                    $location['section'],
                    $departmentId,
                    trim($data[7]),
                    trim($data[8])
                );
                // TODO: new products should be added for immediate review
                $this->importStatus->recordResult($response);
            }

            fclose($handle);
        }

        $this->importStatus->completeFile();
    }

    private function fetchProduct($barcode, $storeId)
    {
        $response = $this->proxy->fetchProduct($barcode, $this->companyId, $storeId);

        if ($response['status'] == "FOUND" && !empty($response['product'])) {
            return $response['product'];
        }

        if ($response['status'] == 'NOT_VALID') {
            $this->importStatus->addInvalidBarcode($barcode);
        }

        return false;
    }

    private function getDepartmentId($dept)
    {
        switch (strtolower($dept)) {
            case 'bulk':
            case 'grocery':
                return "c72a5fa5-01ce-0f2f-e8b3-1220128864ea"; // Grocery
            case 'supplement':
                return "88ea9479-46c6-b9e2-9399-8151a2c9762e"; // Wellness
            case 'chill':
                return "5c40209f-0132-d5d4-4bd4-d71ae354673d"; // Dairy
            case 'natliving':
            case 'produce':
            case 'cosmetics':
            case 'frozen':
                return false;
        }

        $this->importStatus->addInvalidDepartment($dept);
        return "c72a5fa5-01ce-0f2f-e8b3-1220128864ea"; // Grocery
    }

    private function importMetricsFile($file)
    {
        $this->importStatus->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                $this->importStatus->recordRow();

                $upc = trim($data[1]);
                if ($this->importStatus->isInvalidBarcode($upc)) {
                    continue;
                }

                $storeId = $this->importStatus->storeNumToStoreId(intval($data[0]));
                if ($storeId === false) {
                    continue;
                }

                $response = $this->proxy->persistMetric(
                    $upc,
                    $storeId,
                    $this->parsePositiveFloat($data[10]),
                    $this->parsePositiveFloat($data[9]),
                    $this->parsePositiveFloat($data[8])
                );

                if (!$this->importStatus->recordResult($response)) {
                    $this->importStatus->addInvalidBarcode($upc);
                }
            }

            fclose($handle);
        }

        $this->importStatus->completeFile();
    }

    private function parseLocation($data)
    {
        $aisle = trim($data[3]);

        if (!empty($aisle)) {
            $section = trim($data[5]);

            // Skipping seasonal
            if ($section === 'GHOLIDAY') {
                return false;
            }

            if (strpos($section, $aisle) !== false) {
                $section = substr($section, strlen($aisle));
            }

            return [
                'aisle' => $aisle,
                'section' => $section,
            ];
        }

        return false;
    }

    private function parsePositiveFloat($value): float
    {
        $float = floatval($value);
        return $float < 0 ? 0 : $float;
    }
}
