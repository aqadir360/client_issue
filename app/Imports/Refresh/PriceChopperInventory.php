<?php

namespace App\Imports\Refresh;

use App\Imports\ImportInterface;
use App\Imports\Settings\PriceChopperSettings;
use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;
use App\Objects\InventoryCompare;

// [0] Store
// [1] SKU
// [2] UPC
// [3] Dept
// [4] Category
// [5] Product Description
// [6] Product Size
// [7] Avg 90 Day Sales
// [8] Total 90 Day Sales
// [9] Avg Retl
// [10] Avg Cost
// [11] Aisle
// [12] Side
// [13] Section X-Coord
// [14] Section Y-Coord
// [15] Item Position
class PriceChopperInventory implements ImportInterface
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
        $files = glob(storage_path('imports/pricechopper/*.csv'));

        foreach ($files as $file) {
            $this->importInventory($file);
        }

        $this->import->completeImport();
    }

    private function importInventory($file)
    {
        $storeNum = $this->getStoreNum(basename($file));
        $storeId = $this->import->storeNumToStoreId($storeNum);

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
            while (($data = fgetcsv($handle, 10000, ",")) !== false) {
                $upc = BarcodeFixer::fixUpc($data[2]);
                if ($this->import->isInvalidBarcode($upc, $data[2])) {
                    $this->import->writeFileOutput($data, "Skip: Invalid Barcode");
                    continue;
                }

                $sku = trim($data[1]);
                $product = $this->import->fetchProduct($upc, null, $sku);
                if ($product->isExistingProduct === false) {
                    $product->setDescription(trim($data[5]));
                    $product->setSize(trim($data[6]));
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

                $location = PriceChopperSettings::parseLocation($data);
                if ($location->valid === false) {
                    $this->import->recordSkipped();
                    $this->import->writeFileOutput($data, "Skip: Invalid Location");
                    continue;
                }

                $this->import->recordCategory($product, trim($data[3]), trim($data[4]));

                $departmentId = $this->import->getDepartmentId(trim(strtolower($data[3])), trim(strtolower($data[4])), $upc);
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
                        $this->import->convertFloatToInt(floatval($data[10])),
                        $this->import->convertFloatToInt(floatval($data[9])),
                        $this->import->convertFloatToInt(floatval($data[7])),
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

    private function getStoreNum(string $filename)
    {
        return intval(substr($filename, 0, -4));
    }
}
