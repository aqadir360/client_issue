<?php

namespace App\Imports\Refresh;

use App\Imports\ImportInterface;
use App\Models\Location;
use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;
use App\Objects\InventoryCompare;

// Cub Foods Inventory Comparison
// [0] SKU
// [1] UPC
// [2] Aisle
// [3] Section
// [4] Department
// [5] SubDepartment (use as Department)
// [6] Category
// [7] SubCategory (use as Category)
// [8] Description
// [9] Size
// [10] Movement
// [11] Price
// [12] Cost
// [13] Reclaim_Status (not implemented)
class CubFoodsInventory implements ImportInterface
{
    /** @var ImportManager */
    private $import;

    public function __construct(ImportManager $importManager)
    {
        $this->import = $importManager;
        $this->import->setSkipList();
        $this->import->setCategories();
    }

    public function importUpdates()
    {
        $files = $this->import->downloadFilesByName('cub_');
        foreach ($files as $file) {
            $this->importInventory($file);
        }
        $this->import->completeImport();
    }

    private function importInventory($file)
    {
        $storeId = $this->parseStoreNum($file);
        if ($storeId === false) {
            return;
        }

        $compare = new InventoryCompare($this->import, $storeId);
        $this->import->startNewFile($file);
        $this->setFileInventory($compare, $file, $storeId);

        $compare->setExistingInventory();
        $compare->compareInventorySets();
        $this->import->completeFile();
    }

    private function setFileInventory(InventoryCompare $compare, string $file, string $storeId)
    {
        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 10000, "|")) !== false) {
                $upc = BarcodeFixer::fixUpc(trim($data[1]));
                if ($this->import->isInvalidBarcode($upc, $data[1])) {
                    $this->import->writeFileOutput($data, "Skip: Invalid Barcode");
                    continue;
                }

                $product = $this->import->getOrCreateProduct($upc, $data[8], $data[9], $data[0]);
                if ($product === null) {
                    continue;
                }

                $location = $this->parseLocation($data);
                if ($location->valid === false) {
                    $this->import->recordSkipped();
                    $this->import->writeFileOutput($data, "Skip: Invalid Location");
                    continue;
                }

                $departmentId = $this->import->getDeptIdAndRecordCategory($product, trim($data[5]), trim($data[7]));
                if ($departmentId === false) {
                    $this->import->writeFileOutput($data, "Skip: Invalid Department");
                    continue;
                }

                $compare->setFileInventoryItem(
                    $product,
                    $location,
                    $departmentId
                );

                $this->import->persistMetric(
                    $storeId,
                    $product,
                    $this->import->convertFloatToInt(floatval($data[12])),
                    $this->import->convertFloatToInt(floatval($data[11])),
                    $this->import->convertFloatToInt(floatval($data[10])),
                );

                $this->import->writeFileOutput($data, "Success: Valid Product");
            }

            fclose($handle);
        }

        return $compare->fileInventoryCount() > 0;
    }

    private function parseStoreNum($filename)
    {
        $store = substr($filename, strpos($filename, '_') + 1);
        $storeNum = intval(substr($store, 0, strpos($store, '_')));
        return $this->import->storeNumToStoreId($storeNum);
    }

    private function parseLocation(array $data): Location
    {
        $location = new Location();

        $location->aisle = trim($data[2]);
        $location->section = trim($data[3]);
        $location->valid = true;

        return $location;
    }
}
