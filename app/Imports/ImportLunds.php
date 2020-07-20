<?php

namespace App\Imports;

use App\Objects\Api;
use App\Objects\BarcodeFixer;
use App\Objects\ImportFtpManager;
use App\Objects\ImportStatusOutput;
use Exception;

// Downloads files added to Lunds FTP since the last run
class ImportLunds implements ImportInterface
{
    private $companyId;
    private $path;
    private $departments;
    private $skip = [];

    /** @var ImportStatusOutput */
    private $importStatus;

    /** @var Api */
    private $proxy;

    /** @var ImportFtpManager */
    private $ftpManager;

    public function __construct(Api $api)
    {
        $this->proxy = $api;
        $this->path = storage_path('imports/lunds/');
        $this->ftpManager = new ImportFtpManager('imports/lunds/', 'lunds/imports');
        $this->importStatus = new ImportStatusOutput($this->companyId, "Lunds");

        $this->skip = $this->ftpManager->getSkipList();
    }

    public function importUpdates()
    {
        $importList = [];
        $discoList = [];
        $metricsList = [];

        $files = $this->ftpManager->getRecentlyModifiedFiles();
        foreach ($files as $file) {
            if (strpos($file, 'Aisle_Changes') !== false) {
                $importList[] = $this->ftpManager->downloadFile($file);
            } elseif (strpos($file, 'Discontinued') !== false) {
                $discoList[] = $this->ftpManager->downloadFile($file);
            } elseif (strpos($file, 'All_Items') !== false) {
                $metricsList[] = $this->ftpManager->downloadFile($file);
            } elseif (strpos($file, 'Exclude') !== false && strpos($file, 'csv') !== false) {
                $this->addToSkipList($this->ftpManager->downloadFile($file));
            }
        }

        $this->setDepartments();
        $this->importStatus->setStores($this->proxy);

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
        $this->importStatus->outputResults();
    }

    private function importDiscoFile($file)
    {
        $this->importStatus->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                $this->importStatus->recordRow();

                $storeId = $this->importStatus->storeNumToStoreId($data[0]);
                if ($storeId === false) {
                    continue;
                }

                $upc = $this->fixBarcode($data[1]);
                if ($this->importStatus->isInvalidBarcode($upc)) {
                    continue;
                }

                $this->discontinue($upc, $storeId);
            }
        }

        $this->importStatus->completeFile();
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
        $this->importStatus->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                $storeId = $this->importStatus->storeNumToStoreId($data[0]);
                if ($storeId === false) {
                    continue;
                }

                $upc = $this->fixBarcode($data[1]);
                if ($this->importStatus->isInvalidBarcode($upc)) {
                    continue;
                }

                if ($this->persistMetric($upc, $storeId, $data)) {
                    $this->importStatus->currentFile->success++;
                }

                $this->proxy->createVendor($upc, trim($data[8]), $this->companyId);
            }
        }

        fclose($handle);
        $this->importStatus->completeFile();
    }

    private function importActiveFile($file)
    {
        $this->importStatus->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                $this->importStatus->recordRow();

                // skip items with zero or empty tag quantity
                if (intval($data[5]) <= 0) {
                    $this->importStatus->currentFile->skipped++;
                    continue;
                }

                $storeId = $this->importStatus->storeNumToStoreId($data[0]);
                if ($storeId === false) {
                    continue;
                }

                if (isset($this->skip[intval($data[1])])) {
                    $this->importStatus->currentFile->skipped++;
                    continue;
                }

                $upc = $this->fixBarcode($data[1]);
                if ($this->importStatus->isInvalidBarcode($upc)) {
                    continue;
                }

                $action = trim($data[9]);
                if ($action === 'To Discontinue' || $action === 'Discontinued') {
                    $this->discontinue($upc, $storeId);
                } else {
                    $departmentId = $this->deptNameToDeptId($data[7]);
                    if ($departmentId === false) {
                        continue;
                    }

                    $expires = (string)date('Y-m-d 00:00:00', strtotime($data[6]));
                    if (empty($expires)) {
                        $this->importStatus->currentFile->errors++;
                        continue;
                    }

                    $location = $this->parseLocation($data[4]);
                    if (intval($location['aisle']) === 127) {
                        // move these items to the pet department
                        $departmentId = $this->deptNameToDeptId('pet');
                    }

                    $product = $this->importStatus->fetchProduct($this->proxy, $upc, $storeId);
                    if ($product === null) {
                        // invalid barcode
                        continue;
                    }

                    if (false === $product) {
                        $this->createNewProductAndItem($data, $upc, $storeId, $departmentId, $location);
                    } else {
                        $this->handleExistingProduct($data, $upc, $product, $storeId, $departmentId, $location);
                    }
                }
            }

            fclose($handle);
        }

        $this->importStatus->completeFile();
    }

    private function createNewProductAndItem($row, $upc, $storeId, $departmentId, $location)
    {
        $response = $this->proxy->implementationScan(
            $upc,
            $storeId,
            $location['aisle'],
            $location['section'],
            $departmentId,
            ucwords(strtolower(trim($row[2]))),
            strtolower($row[3])
        );
        $this->importStatus->recordResult($response);

        $this->proxy->createVendor($upc, trim($row[8]), $this->companyId);
        $this->persistMetric($upc, $storeId, $row);
    }

    private function handleExistingProduct($row, $upc, $product, $storeId, $departmentId, $location)
    {
        $item = $this->getInventoryItem($product, $location, $departmentId);

        if ($item !== false) {
            if ($this->needToMoveItem($item, $location)) {
                $this->moveInventory($item, $storeId, $location);
            } else {
                $this->importStatus->currentFile->skipped++;
            }

            $this->persistExistingItemMetric($upc, $storeId, $item, $row);
        } else {
            $response = $this->proxy->implementationScan(
                $upc,
                $storeId,
                $location['aisle'],
                $location['section'],
                $departmentId
            );
            $this->importStatus->recordResult($response);

            $this->persistMetric($upc, $storeId, $row);
        }

        if (strtoupper($product['vendor']) != strtoupper(trim($row[8]))) {
            $this->proxy->createVendor($upc, trim($row[8]), $this->companyId);
        }
    }

    private function getInventoryItem($product, $location, $departmentId)
    {
        if (count($product['inventory']) === 0) {
            return false;
        }

        // use exact match
        foreach ($product['inventory'] as $item) {
            if ($item['aisle'] == $location['aisle'] && $item['section'] == $location['section'] && $item['departmentId'] == $departmentId) {
                return $item;
            }
        }

        // use aisle match
        foreach ($product['inventory'] as $item) {
            if ($item['aisle'] == $location['aisle']) {
                return $item;
            }
        }

        // use department match
        foreach ($product['inventory'] as $item) {
            if ($item['departmentId'] == $departmentId) {
                return $item;
            }
        }

        // use any non markdown section item
        foreach ($product['inventory'] as $item) {
            if ($item['aisle'] != 'MKDN') {
                return $item;
            }
        }

        return false;
    }

    private function needToMoveItem($item, $location)
    {
        return !($item['aisle'] == $location['aisle'] && $item['section'] == $location['section']);
    }

    private function discontinue($upc, $storeId)
    {
        $response = $this->proxy->discontinueProductByBarcode($storeId, $upc);
        return $this->importStatus->recordResult($response);
    }

    private function moveInventory(
        $item,
        $storeId,
        $location
    ) {
        $response = $this->proxy->updateInventoryLocation(
            $item['inventoryItemId'],
            $storeId,
            $item['departmentId'], // do not overwrite the existing department
            $location['aisle'],
            $location['section']
        );
        return $this->importStatus->recordResult($response);
    }

    private function persistMetric($upc, $storeId, $row)
    {
        if (count($row) >= 12) {
            $cost = floatval($row[10]);
            $retail = floatval($row[11]);
            // sending weekly movement
            $movement = round(floatval($row[12]) / 7, 4);

            if ($cost != 0 || $retail != 0 || $movement != 0) {
                $response = $this->proxy->persistMetric($upc, $storeId, $cost, $retail, $movement);
                return $this->proxy->validResponse($response);
            }
        }

        return false;
    }

    private function persistExistingItemMetric($upc, $storeId, $item, $row)
    {
        if (!empty($row[10]) || !empty($row[11]) || !empty($row[12])) {
            $cost = floatval($row[10]);
            $retail = floatval($row[11]);
            $movement = round(floatval($row[12]) / 7, 4);

            if (!($item['cost'] == number_format($cost, 2)
                && $item['retail'] == number_format($retail, 2)
                && $item['movement'] == number_format($movement, 2))) {

                // avoid overwriting with empty values
                if ($row[10] === '') {
                    $cost = floatval($item['cost']);
                }

                if ($row[11] === '') {
                    $retail = floatval($item['retail']);
                }

                $this->proxy->persistMetric($upc, $storeId, $cost, $retail, $movement);
            }
        }
    }

    private function setDepartments()
    {
        $response = $this->proxy->fetchDepartments($this->companyId);

        foreach ($response['departments'] as $department) {
            $this->departments[$this->normalizeName($department['name'])] = $department['departmentId'];
        }
    }

    private function deptNameToDeptId($input)
    {
        $deptName = $this->normalizeName($input);

        if (isset($this->departments[$deptName])) {
            return $this->departments[$deptName];
        } else {
            $this->importStatus->addInvalidDepartment($input);
            $this->importStatus->currentFile->errors++;
            return false;
        }
    }

    private function normalizeName($input)
    {
        $output = strtolower(preg_replace('![^a-z0-9]+!i', '', $input));

        switch ($output) {
            case 'meat':
                return 'processedmeat';
            case 'seafood':
                return 'frozenmeatseafood';
            case 'frozenfood':
                return 'frozen';
            case 'healthandbeauty':
                return 'hbc30day';
            default:
                return $output;
        }
    }

    private function parseLocation($location)
    {
        $aisle = '';
        $section = '';

        if (strlen($location) > 0) {
            $exploded = explode('-', $location);
            if (count($exploded) > 1) {
                // preserve leading zeros
                $aisle = trim($exploded[0]);
                $section = trim($exploded[1]);
            }
        }

        return [
            'aisle' => $aisle,
            'section' => $section,
        ];
    }

    private function fixBarcode($barcode)
    {
        if (strlen($barcode) == 14) {
            return substr($barcode, 1);
        }
        while (strlen($barcode) < 13) {
            $barcode = '0' . $barcode;
        }
        return $barcode;
    }

    private function addToSkipList($file)
    {
        $this->importStatus->startNewFile($file);

        // writes any new upcs to skip file
        $addToSkipList = [];
        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                $upc = trim($data[0]);
                if ($upc != 'UPCs to Exclude') {
                    $this->importStatus->recordRow();

                    if (!isset($this->skip[intval($upc)])) {
                        $addToSkipList[] = $upc;
                    }

                    $success = $this->pushBarcodeToSkipList($upc);
                    $this->importStatus->recordResult($success);
                }
            }

            fclose($handle);
        }

        if (count($addToSkipList) > 0) {
            if (($handle = fopen($this->path . 'skip.csv', "a")) !== false) {
                foreach ($addToSkipList as $skip) {
                    fwrite($handle, $skip . PHP_EOL);
                }
                fclose($handle);
            }
        }

        $this->importStatus->completeFile();
    }

    private function pushBarcodeToSkipList($barcode)
    {
        $this->skip[intval($barcode)] = true;

        try {
            $parsed = BarcodeFixer::fixUpc($barcode);
            $this->skip[intval($parsed)] = true;
            return true;
        } catch (Exception $e) {
            // Ignore invalid
        }

        return false;
    }
}
