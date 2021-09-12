<?php

namespace App\Imports\Refresh;

use App\Imports\ImportInterface;
use App\Models\Location;
use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;
use App\Objects\InventoryCompare;

// Pulls two sets of files per Raley's store to refresh inventory locations
//
// Location files: Compare Inventory Sets
// Master files: Fill in missing items and new product data
class RaleysInventory implements ImportInterface
{
    private $skus = [];

    /** @var ImportManager */
    private $import;

    public function __construct(ImportManager $importManager)
    {
        $this->import = $importManager;
        $this->import->setSkipList();
        $this->setSkus();
    }

    public function importUpdates()
    {
        $stores = $this->import->getStores();
        $files = $this->import->ftpManager->getRecentlyModifiedFiles();

        $downloadedFiles = [];

        foreach ($stores as $storeNum => $storeId) {
            $locationFile = $this->import->downloadStoreFileByName($files, 'dcp_aisle_locations_full_as_of_', $storeNum);
            $masterFile = $this->import->downloadStoreFileByName($files, 'dcp_item_master_as_of_', $storeNum);

            if ($locationFile !== null && $masterFile !== null) {
                $downloadedFiles[$storeId] = [
                    $locationFile,
                    $masterFile
                ];
            }
        }

        foreach ($downloadedFiles as $storeId => $file) {
            $this->importInventory($storeId, $file[0], $file[1]);
        }

        $this->import->completeImport();
    }

    private function importInventory($storeId, $locationFile, $masterFile)
    {
        $compare = new InventoryCompare($this->import, $storeId, 0);

        $locationInventory = $this->readInLocationFile($locationFile, $storeId);
        $fullInventory = $this->readInMasterFile($masterFile, $locationInventory);

        foreach ($fullInventory as $barcode => $item) {
            $compare->setFileInventoryItem(
                $item[0]->barcode,
                $item[1],
                $item[0]->description,
                $item[0]->size,
                $item[2]
            );
        }

        $this->import->startNewFile($locationFile);
        $compare->setExistingInventory();
        $compare->compareInventorySets();
        $this->import->completeFile();
    }

    private function readInMasterFile(string $masterFile, array $locationInventory): array
    {
        $this->import->startNewFile($masterFile);

        if (($handle = fopen($masterFile, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                if (strpos($data[0], 'SKU') !== false) {
                    continue;
                }

                $this->import->recordRow();

                $barcode = BarcodeFixer::fixLength($data[1]);
                if ($this->import->isInvalidBarcode($barcode, $data[1])) {
                    $this->import->writeFileOutput($data, "Skip: Invalid Barcode");
                    continue;
                }

                // Skip if all info is already filled
                if (isset($locationInventory[intval($barcode)]) && $locationInventory[intval($barcode)][0]->isExistingProduct === true) {
                    continue;
                }

                if ($this->import->isInSkipList($barcode)) {
                    $this->import->writeFileOutput($data, "Skip: Skip List");
                    continue;
                }

                $product = $this->import->fetchProduct($barcode);
                if ($product->isExistingProduct === false) {
                    $product->setDescription($data[3]);
                    $product->setSize($data[8]);
                    $this->import->createProduct($product);
                    $this->recordSku(trim($data[0]), $barcode);
                }

                if (isset($locationInventory[intval($barcode)])) {
                    $locationInventory[intval($barcode)][0] = $product;
                    $this->import->writeFileOutput($data, "Success: Filled Location File Product Info");
                } else {
                    $location = $this->normalizeRaleysLocation($data[9]);
                    $deptId = $this->import->getDepartmentId($data[5], $data[6]);

                    if ($location->valid && $deptId !== false) {
                        $locationInventory[intval($product->barcode)] = [$product, $location, $deptId];
                        $this->import->writeFileOutput($data, "Success: Valid Inventory");
                    } else {
                        $this->import->writeFileOutput($data, "Skip: Invalid Location");
                    }
                }
            }

            fclose($handle);
        }

        $this->import->completeFile();

        return $locationInventory;
    }

    private function readInLocationFile(string $locationFile, string $storeId): array
    {
        $this->import->startNewFile($locationFile);

        $locationInventory = [];
        if (($handle = fopen($locationFile, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                if (strpos($data[0], 'sku') !== false) {
                    continue;
                }

                $this->import->recordRow();

                $barcode = BarcodeFixer::fixLength($data[1]);
                if ($this->import->isInvalidBarcode($barcode, $data[1])) {
                    $this->import->writeFileOutput($data, "Skip: Invalid Barcode");
                    continue;
                }

                if ($this->import->isInSkipList($barcode)) {
                    $this->import->writeFileOutput($data, "Skip: Skip List");
                    continue;
                }

                $location = $this->normalizeRaleysLocation($data[3]);
                if (!$location->valid) {
                    $this->import->writeFileOutput($data, "Skip: Invalid Location");
                    $this->import->recordSkipped();
                    continue;
                }

                $departmentId = $this->import->getDepartmentId(trim($data[6]), trim($data[7]));
                if (!$departmentId) {
                    $this->import->writeFileOutput($data, "Skip: Invalid Department");
                    continue;
                }

                $product = $this->import->fetchProduct($barcode, $storeId);
                $locationInventory[intval($product->barcode)] = [$product, $location, $departmentId];
                $this->import->writeFileOutput($data, "Success: Valid Inventory");
            }

            fclose($handle);
        }

        $this->import->completeFile();

        return $locationInventory;
    }

    private function normalizeRaleysLocation(string $input): Location
    {
        if (empty($input)) {
            return new Location();
        }

        if (strlen($input) > 0 && ($input[0] == "W" || $input[0] == "G" || $input[0] == "D")) {
            $input = substr($input, 1);
        }

        $location = new Location();
        $location->aisle = substr($input, 0, 2);
        $location->section = strlen($input) > 2 ? substr($input, 2) : '';

        if (!empty(trim($location->aisle))) {
            $location->valid = true;
        }

        return $location;
    }

    private function setSkus()
    {
        $rows = $this->import->db->fetchRaleysSkus();

        foreach ($rows as $row) {
            $this->skus[intval($row->sku_num)][] = [$row->barcode];
        }
    }

    private function recordSku($sku, $barcode)
    {
        if (!isset($this->skus[intval($sku)])) {
            $this->skus[intval($sku)] = $barcode;
            $this->import->db->insertRaleysSku($sku, $barcode);
        }
    }
}
