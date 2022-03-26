<?php

namespace App\Imports\Refresh;

use App\Imports\ImportInterface;
use App\Models\Location;
use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;
use App\Objects\InventoryCompare;

// Expects all store inventory in one file
// [0] Store
// [1] SKU
// [2] Barcode
// [3] Aisle
// [4] Section
// [5] Shelf
// [6] Department Name
// [7] Category
// [8] Product Description
// [9] Product Size
// [10] 90 day average daily units sold
// [11] Retail
// [12] Cost
class AlaskaInventory implements ImportInterface
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
        $files = glob(storage_path('imports/alaska/*.csv'));

        foreach ($files as $file) {
            $this->importInventory($file);
        }

        $this->import->completeImport();
    }

    private function importInventory($file)
    {
        $id_array = array();
        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 10000, "|")) !== false) {
                $storeNum = intval($data[0]);

                if (!in_array($storeNum, $id_array)) {
                    $id_array[] = $storeNum;
                    $storeId = $this->import->storeNumToStoreId($storeNum);
                    if ($storeId === false) {
                        continue;
                    }

                    $compare = new InventoryCompare($this->import, $storeId);
                    $this->import->startNewFile($file, "_" . $storeNum . "_");
                    $this->setFileInventory($compare, $file, $storeId, intval($storeNum));
                    $compare->setExistingInventory();
                    $compare->compareInventorySets();
                    $this->import->completeFile(false);
                }
            }
        }

        unlink($file);
    }

    private function setFileInventory(InventoryCompare $compare, string $file, string $storeId, int $storeNum)
    {
        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 10000, "|")) !== false) {
                if (intval($data[0]) !== $storeNum) {
                    continue;
                }

                $upc = BarcodeFixer::fixLength($data[2]);
                if ($this->import->isInvalidBarcode($upc, $data[2])) {
                    $this->import->writeFileOutput($data, "Skip: Invalid Barcode");
                    continue;
                }

                $sku = trim($data[1]);
                $product = $this->import->fetchProduct($upc, null, $sku);
                if ($product->isExistingProduct === false) {
                    $product->setDescription(trim($data[8]));
                    $product->setSize($this->parseSize(trim($data[9])));
                    $productId = $this->import->createProduct($product);

                    if ($productId) {
                        $product->setProductId($productId);
                    } else {
                        $this->import->writeFileOutput($data, "Skip: Invalid Product");
                        continue;
                    }
                }

                if ($product->sku !== $sku) {
                    $this->import->db->setProductSku($product->productId, $sku);
                }

                $location = $this->parseLocation($data);

                if ($location->valid === false) {
                    $this->import->recordSkipped();
                    $this->import->writeFileOutput($data, "Skip: Invalid Location");
                    continue;
                }

                $departmentId = $this->import->getDeptIdAndRecordCategory($product, trim($data[6]), trim($data[7]));
                if ($departmentId === false) {
                    $this->import->writeFileOutput($data, "Skip: Invalid Department");
                    continue;
                }

                $compare->setFileInventoryItem(
                    $product,
                    $location,
                    $departmentId
                );

                if ($product && $product->isExistingProduct) {
                    $this->import->persistMetric(
                        $storeId,
                        $product,
                        $this->import->convertFloatToInt(floatval($data[12])),
                        $this->import->convertFloatToInt(floatval($data[11])),
                        $this->import->convertFloatToInt(floatval($data[10])),
                    );

                    if ($location->valid) {
                        $this->import->writeFileOutput($data, "Success: Valid Product");
                    } else {
                        $this->import->writeFileOutput($data, "Skipped: Invalid Location");
                    }
                } else {
                    $this->import->writeFileOutput($data, "Skipped: New Product");
                }
            }

            fclose($handle);
        }

        return $compare->fileInventoryCount() > 0;
    }

    private function parseSize($input)
    {
        return str_replace('#', 'lb', str_replace('<', 'oz', $input));
    }

    private function parseLocation(array $data): Location
    {
        $location = new Location();

        if (!empty($data[3]) || !empty($data[4])) {
            $location->aisle = trim($data[3]);
            $location->section = trim($data[4]);
            $location->shelf = trim($data[5]);
            $location->valid = true;
        }

        return $location;
    }
}
