<?php

namespace App\Imports;

use App\Models\Location;
use App\Objects\Api;
use App\Objects\BarcodeFixer;
use App\Objects\Database;
use App\Objects\FtpManager;
use App\Objects\ImportManager;

// Vallarta Inventory and Metrics Import
// Expects update and metrics files weekly
// Adds all products with unknown location and grocery department
class ImportVallarta implements ImportInterface
{
    private $companyId = 'c3c9f97e-e095-1f19-0c5e-441da2520a9a';

    /** @var ImportManager */
    private $import;

    /** @var Api */
    private $proxy;

    /** @var FtpManager */
    private $ftpManager;

    public function __construct(Api $api, Database $database)
    {
        $this->proxy = $api;
        $this->ftpManager = new FtpManager('vallarta/imports');
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
            } elseif (strpos($file, 'metrics_') !== false) {
                $metricsList[] = $this->ftpManager->downloadFile($file);
            }
        }

        foreach ($updateList as $filePath) {
            $this->importActiveFile($filePath);
        }

        foreach ($metricsList as $filePath) {
            $this->importMetricsFile($filePath);
        }

        $this->completeImport();
    }

    public function completeImport(string $error = '')
    {
        $this->proxy->triggerUpdateCounts($this->companyId);
        $this->ftpManager->writeLastDate();
        $this->import->completeImport($error);
    }

    private function importActiveFile($file)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                $this->import->recordRow();

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
                        $this->import->currentFile->skipped++;
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
            $this->import->currentFile->skipped++;
            return;
        }

        if ($product->isExistingProduct === false) {
            $product->setDescription($data[6]);
            $product->setSize($data[7]);
        }

        $response = $this->proxy->implementationScan(
            $product,
            $storeId,
            trim($data[3]),
            trim($data[4]),
            $departmentId
        );

        $this->import->recordAdd($response);
    }

    private function handleMove($barcode, $storeId, $department, Location $location)
    {
        $deptId = $this->import->getDepartmentId(trim(strtolower($department)));
        if ($deptId === false) {
            return;
        }

        if ($this->shouldSkip($location->aisle, $location->section)) {
            $this->import->currentFile->skipped++;
            return;
        }

        $product = $this->import->fetchProduct($barcode, $storeId);
        if ($product->isExistingProduct === false) {
            // Moves do not include product information
            $this->import->currentFile->skipped++;
            return;
        }

        if ($product->hasInventory()) {
            $item = $product->getMatchingInventoryItem($location, $deptId);

            if ($item !== null) {
                $response = $this->proxy->updateInventoryLocation(
                    $item->inventory_item_id,
                    $storeId,
                    $deptId,
                    $location->aisle,
                    $location->section
                );
                $this->import->recordAdd($response);
                return;
            }
        }

        // Adding as new any moves that do not exist in inventory
        $response = $this->proxy->implementationScan(
            $product,
            $storeId,
            $location->aisle,
            $location->section,
            $deptId
        );

        $this->import->recordAdd($response);
    }

    private function importMetricsFile($file)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                $this->import->recordRow();

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
                    $this->import->currentFile->skipped++;
                    continue;
                }

                $success = $this->import->persistMetric(
                    $storeId,
                    $product->productId,
                    $this->import->convertFloatToInt(floatval($data[4])),
                    $this->import->convertFloatToInt(floatval($data[3])),
                    $this->import->convertFloatToInt(floatval($data[2]))
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
