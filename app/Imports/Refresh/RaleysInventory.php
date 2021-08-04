<?php

namespace App\Imports\Refresh;

use App\Imports\ImportInterface;
use App\Models\Location;
use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;

// Pulls two sets of files per Raley's store to refresh inventory locations
//
// Location files: Move any UPC with existing inventory to the new location
//  No create or disco
// Master files: Add any items not currently in inventory that have a location
//  No move or disco
// Both: Ignore items with no location
class RaleysInventory implements ImportInterface
{
    private $skus = [];

    /** @var ImportManager */
    private $import;

    public function __construct(ImportManager $importManager)
    {
        $this->import = $importManager;
        $this->import->setSkipList();
    }

    public function importUpdates()
    {
        $locationFiles = [];
        $masterFiles = [];

        $files = $this->import->ftpManager->getRecentlyModifiedFiles();
        foreach ($files as $file) {
            if (strpos($file, 'dcp_aisle_locations_full_as_of_') !== false) {
                $locationFiles[] = $this->import->ftpManager->downloadFile($file);
            } elseif (strpos($file, 'dcp_item_master_as_of_') !== false) {
                $masterFiles[] = $this->import->ftpManager->downloadFile($file);
            }
        }

        $this->setSkus();

        foreach ($masterFiles as $file) {
            $this->importMasterFile($file);
        }

        foreach ($locationFiles as $file) {
            $this->importLocationsFile($file);
        }

        $this->import->completeImport();
    }

    // Add any items not currently in inventory that have a location
    private function importMasterFile($file)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                if (strpos($data[0], 'SKU') !== false) {
                    continue;
                }

                if (!$this->import->recordRow()) {
                    break;
                }

                $barcode = BarcodeFixer::fixLength($data[1]);
                if ($this->import->isInvalidBarcode($barcode, $data[1])) {
                    $this->import->writeFileOutput($data, "Skip: Invalid Barcode");
                    continue;
                }

                if ($this->import->isInSkipList($barcode)) {
                    $this->import->writeFileOutput($data, "Skip: Skip List");
                    continue;
                }

                $storeId = $this->import->storeNumToStoreId(trim($data[2]));
                if ($storeId === false) {
                    $this->import->writeFileOutput($data, "Skip: Store Not Found");
                    continue;
                }

                $location = $this->normalizeRaleysLocation($data[9]);
                if (!$location->valid) {
                    $this->import->recordSkipped();
                    $this->import->writeFileOutput($data, "Skip: Invalid Location");
                    continue;
                }

                $product = $this->import->fetchProduct($barcode, $storeId);
                if ($product->hasInventory()) {
                    $this->import->recordStatic();
                    $this->import->writeFileOutput($data, "Static: Existing Inventory");
                    continue;
                }

                $deptId = $this->import->getDepartmentId($data[5], $data[6]);
                if ($deptId === false) {
                    $this->import->writeFileOutput($data, "Skip: Invalid Department");
                    continue;
                }

                if ($product->isExistingProduct === false) {
                    $product->setDescription($data[3]);
                    $product->setSize($data[8]);
                }

                $success = $this->import->implementationScan(
                    $product,
                    $storeId,
                    $location->aisle,
                    $location->section,
                    $deptId
                );

                if (!is_null($success)) {
                    $this->import->writeFileOutput($data, "Success: Created Inventory");
                } else {
                    $this->import->writeFileOutput($data, "Error: Could Not Create Inventory");
                }

                $this->recordSku(trim($data[0]), $barcode);
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }

    // Move any UPC with existing inventory to the new location
    private function importLocationsFile($file)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                if (strpos($data[0], 'sku') !== false) {
                    continue;
                }

                if (!$this->import->recordRow()) {
                    break;
                }

                $barcode = BarcodeFixer::fixLength($data[1]);
                if ($this->import->isInvalidBarcode($barcode, $data[1])) {
                    $this->import->writeFileOutput($data, "Skip: Invalid Barcode");
                    continue;
                }

                $storeId = $this->import->storeNumToStoreId(trim($data[2]));
                if ($storeId === false) {
                    $this->import->writeFileOutput($data, "Skip: Invalid Store");
                    continue;
                }

                $product = $this->import->fetchProduct($barcode, $storeId);
                if ($product->isExistingProduct === false) {
                    $this->import->recordSkipped();
                    $this->import->writeFileOutput($data, "Skip: New Product");
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

                $item = $product->getMatchingInventoryItem($location, $departmentId);
                if ($item === null) {
                    $this->import->writeFileOutput($data, "Skip: Match Not Found");
                    $this->import->recordSkipped();
                    continue;
                }

                if ($this->needToMoveItem($item, $location, $departmentId)) {
                    $success = $this->import->updateInventoryLocation(
                        $item->inventory_item_id,
                        $storeId,
                        $departmentId,
                        $location->aisle,
                        $location->section
                    );
                    $this->recordLocationUpdate($success, $data, $location->aisle, $location->section);
                } else {
                    $this->import->writeFileOutput($data, "Static: No Move Needed");
                    $this->import->recordStatic();
                }
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }

    private function recordLocationUpdate(bool $success, array $data, $aisle, $section)
    {
        if ($success) {
            $this->import->writeFileOutput($data, "Success: Updated Location $aisle $section");
        } else {
            $this->import->writeFileOutput($data, "Error: Could Not Update Location");
        }
    }

    private function needToMoveItem($item, Location $location, string $departmentId): bool
    {
        return !($item->aisle === $location->aisle
            && $item->section === $location->section
            && $item->department_id === $departmentId);
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
