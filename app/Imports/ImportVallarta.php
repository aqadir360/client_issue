<?php

namespace App\Imports;

use App\Objects\Api;
use App\Objects\BarcodeFixer;
use App\Objects\ImportFtpManager;
use App\Objects\ImportStatusOutput;

class ImportVallarta implements ImportInterface
{
    private $companyId = 'c3c9f97e-e095-1f19-0c5e-441da2520a9a';
    private $path;
    private $departments;

    /** @var ImportStatusOutput */
    private $importStatus;

    /** @var Api */
    private $proxy;

    /** @var ImportFtpManager */
    private $ftpManager;

    public function __construct(Api $api)
    {
        $this->proxy = $api;
        $this->path = storage_path('imports/vallarta/');

        $this->ftpManager = new ImportFtpManager('imports/vallarta/', 'vallarta/imports');
        $this->importStatus = new ImportStatusOutput($this->companyId, 'Vallarta');
    }

    public function importUpdates()
    {
        $updateList = [];
        $metricsList = [];

        $files = $this->ftpManager->getRecentlyModifiedFiles();
        foreach ($files as $file) {
            if (strpos($file, 'update_') !== false) {
                $updateList[] = $this->ftpManager->downloadFile($file);
            } elseif (strpos($file, 'metrics_') !== false) {
                $metricsList[] = $this->ftpManager->downloadFile($file);
            }
        }

        $this->setDepartments();
        $this->importStatus->setStores($this->proxy);

        foreach ($updateList as $filePath) {
            $this->importActiveFile($filePath);
        }

        foreach ($metricsList as $filePath) {
            $this->importMetricsFile($filePath);
        }

        $this->proxy->triggerUpdateCounts($this->companyId);
        $this->ftpManager->writeLastDate();
        $this->importStatus->outputResults();
    }

    private function importActiveFile($file)
    {
        $this->importStatus->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                $this->importStatus->recordRow();

                $storeId = $this->importStatus->storeNumToStoreId(intval($data[1]));
                if ($storeId === false) {
                    continue;
                }

                $barcode = $this->fixBarcode(trim($data[2]));
                if ($this->importStatus->isInvalidBarcode($barcode)) {
                    continue;
                }

                switch (trim($data[0])) {
                    case 'disco':
                        // Skipping discontinues due to incorrect timing
                        $this->importStatus->currentFile->skipped++;
                        break;
                    case 'move':
                        $this->handleMove($data, $barcode, $storeId);
                        break;
                    case 'add':
                        $this->addInventory(
                            $barcode,
                            $storeId,
                            trim($data[3]),
                            trim($data[4]),
                            $this->deptNameToDeptId(trim(strtolower($data[5]))),
                            trim(ucwords(strtolower($data[6]))),
                            trim(strtolower($data[7]))
                        );
                        break;
                }
            }

            fclose($handle);
        }

        $this->importStatus->completeFile();
    }

    private function handleMove($data, $barcode, $storeId)
    {
        $aisle = trim($data[5]);
        $section = trim($data[6]);
        $deptId = $this->deptNameToDeptId(trim(strtolower($data[7])));

        if ($this->shouldSkip($aisle, $section)) {
            $this->importStatus->currentFile->skipped++;
            return;
        }

        $product = $this->fetchProduct($barcode, $storeId);
        if ($product === false) {
            // Moves do not include product information
            $this->importStatus->currentFile->skipped++;
            return;
        }

        if (count($product['inventory']) > 0) {
            $item = $this->getItem($product['inventory'], $aisle, $deptId);
            $response = $this->proxy->updateInventoryLocation(
                $item['inventoryItemId'],
                $storeId,
                $deptId,
                $aisle,
                $section
            );
            $this->importStatus->recordResult($response);
        } else {
            // Adding as new any moves that do not exist in inventory
            $this->addInventory(
                $barcode,
                $storeId,
                $aisle,
                $section,
                $deptId,
                $product['description'],
                $product['size']
            );
        }
    }

    private function getItem(array $inventory, $aisle, $deptId)
    {
        if (count($inventory) === 1) {
            return $inventory[0];
        }

        foreach ($inventory as $item) {
            if ($item['aisle'] == $aisle) {
                return $item;
            }
        }

        foreach ($inventory as $item) {
            if ($item['departmentId'] == $deptId) {
                return $item;
            }
        }

        return $inventory[0];
    }

    private function importMetricsFile($file)
    {
        $this->importStatus->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                $this->importStatus->recordRow();

                $storeId = $this->importStatus->storeNumToStoreId(intval($data[0]));
                if ($storeId === false) {
                    continue;
                }

                $barcode = $this->fixBarcode(trim($data[1]));
                if ($this->importStatus->isInvalidBarcode($barcode)) {
                    continue;
                }

                $this->persistMetric(
                    $barcode,
                    $storeId,
                    floatval($data[2]),
                    floatval($data[4]),
                    floatval($data[3])
                );
            }

            fclose($handle);
        }

        $this->importStatus->completeFile();
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

    private function addInventory($barcode, $storeId, $aisle, $section, $deptId, $description, $size)
    {
        $response = $this->proxy->implementationScan(
            $barcode,
            $storeId,
            $aisle,
            $section,
            $deptId,
            $description,
            $size
        );

        $this->importStatus->recordResult($response);
    }

    private function fetchProduct($upc, $storeId = null)
    {
        $response = $this->proxy->fetchProduct($upc, $this->companyId, $storeId);

        if ($response['status'] == "FOUND" && !empty($response['product'])) {
            return $response['product'];
        }

        return false;
    }

    private function persistMetric($barcode, $storeId, $movement, $cost, $retail)
    {
        $response = $this->proxy->persistMetric($barcode, $storeId, $cost, $retail, $movement);

        if ($this->proxy->validResponse($response)) {
            $this->importStatus->currentFile->success++;
        } else {
            if ($response['status'] === 'NOT_VALID') {
                $this->importStatus->addInvalidBarcode($barcode);
                $this->importStatus->currentFile->invalidBarcodeErrors++;
            } else {
                $this->importStatus->currentFile->recordErrorMessage($response);
            }
        }
    }

    private function setDepartments()
    {
        $response = $this->proxy->fetchDepartments($this->companyId);

        foreach ($response['departments'] as $department) {
            $this->departments[strtolower($department['name'])] = $department['departmentId'];
        }
    }

    private function deptNameToDeptId(string $input)
    {
        $name = $this->mapDepartmentNames($input);

        if (isset($this->departments[$name])) {
            return $this->departments[$name];
        } else {
            $this->importStatus->addInvalidDepartment($name);
            return $this->departments['grocery'];
        }
    }

    private function mapDepartmentNames(string $input)
    {
        switch ($input) {
            case 'health & beauty':
            case 'haba restricted':
                return 'otc';
            case 'soda':
            case 'soda no tax':
            case 'uwg soda':
            case 'grocery tax':
                return 'grocery';
            default:
                return $input;
        }
    }

    // Always add check digit
    private function fixBarcode(string $input)
    {
        $upc = str_pad(ltrim($input, '0'), 11, '0', STR_PAD_LEFT);
        return $upc . BarcodeFixer::calculateMod10Checksum($upc);
    }
}
