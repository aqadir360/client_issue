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
                    continue;
                }

                $storeId = $this->import->storeNumToStoreId(trim($data[2]));
                if ($storeId === false) {
                    continue;
                }

                $location = $this->normalizeRaleysLocation($data[9]);
                if (!$location->valid) {
                    $this->import->recordSkipped();
                    continue;
                }

                $product = $this->import->fetchProduct($barcode, $storeId);
                if ($product->hasInventory()) {
                    $this->import->recordStatic();
                    continue;
                }

                $deptId = $this->import->getDepartmentId($data[5], $data[6]);
                if ($deptId === false) {
                    continue;
                }

                if ($product->isExistingProduct === false) {
                    $product->setDescription($data[3]);
                    $product->setSize($data[8]);
                }

                $this->import->implementationScan(
                    $product,
                    $storeId,
                    $location->aisle,
                    $location->section,
                    $deptId
                );

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
                    continue;
                }

                $storeId = $this->import->storeNumToStoreId(trim($data[2]));
                if ($storeId === false) {
                    continue;
                }

                $product = $this->import->fetchProduct($barcode, $storeId);
                if ($product->isExistingProduct === false) {
                    $this->import->recordSkipped();
                    continue;
                }

                $location = $this->normalizeRaleysLocation($data[3]);
                if (!$location->valid) {
                    $this->import->recordSkipped();
                    continue;
                }

                $item = $product->getMatchingInventoryItem($location);
                if ($item === null) {
                    $this->import->recordSkipped();
                    continue;
                }

                if ($this->needToMoveItem($item, $location)) {
                    $this->import->updateInventoryLocation(
                        $item->inventory_item_id,
                        $storeId,
                        $item->department_id,
                        $location->aisle,
                        $location->section
                    );
                } else {
                    $this->import->recordStatic();
                }
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }

    private function needToMoveItem($item, Location $location)
    {
        return !($item->aisle == $location->aisle && $item->section == $location->section);
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
