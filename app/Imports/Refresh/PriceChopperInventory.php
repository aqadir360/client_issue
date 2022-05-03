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
// [7] DSD Item Flag
// [8] Avg 90 Day Sales
// [9] Total 90 Day Sales
// [10] Avg Retl
// [11] Avg Cost
// [12] Aisle
// [13] Side
// [14] Section X-Coord
// [15] Section Y-Coord
// [16] Item Position

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
        $files = $this->import->downloadFilesByName("Store");

        foreach ($files as $file) {
            $storeNum = $this->parseStoreNum($file);
            $storeId = $this->import->storeNumToStoreId($storeNum);
            if (!$storeId) {
                continue;
            }

            $this->importInventory($file, $storeId);
        }

        $this->import->completeImport();
    }

    private function importInventory($file, $storeId)
    {
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

                $departmentId = $this->import->getDeptIdAndRecordCategory($product, trim($data[3]), trim($data[4]));
                if ($departmentId === false) {
                    $this->import->writeFileOutput($data, "Skip: Invalid Department");
                    continue;
                }

                // DSD column check and map departments to the related DSD department if the value is true
                if (trim($data[7]) === 'TRUE') {
                    $departmentId = $this->getRelatedDSDDepartment($departmentId);
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
                        $this->import->convertFloatToInt(floatval($data[11])),
                        $this->import->convertFloatToInt(floatval($data[10])),
                        $this->import->convertFloatToInt(floatval($data[8])),
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

    private function parseStoreNum($file)
    {
        return intval(substr($file, -7, 3));
    }

    private function getRelatedDSDDepartment(string $departmentId): string
    {
        switch ($departmentId) {
            case '06afcad5-4b27-b255-8581-d4e5fda38773': // Dairy
                return 'b330ef77-7eab-223d-afe6-1e7aa3a16adf';
            case '42654c05-235d-599e-da50-7a2f7ad46110': // Dairy - Milk
                return '059e0853-50fe-984e-cc7f-0978d88be2cd';
            case '45e90206-97c8-a94f-8259-00a4aec02cd0': // Grocery - Short Life
                return 'c922e967-18f4-cf59-b0e4-46d71a70586d';
            case '76b5c60c-1bc6-7ae6-588e-bdd424ed2ce9': // Grocery
                return 'f7d6519b-6736-06f2-7c67-0e57ceb3ead5';
            case '80ff8936-3a37-06ec-a212-ed172b97ff85': // Grocery - Baby Food
                return 'a0b57899-81ea-696f-9ff8-6dc5f0df3446';
            case 'a09a6505-d524-925c-92bd-27c2a3fc5dc8': // Dairy - Yogurt
                return '2982ccd9-2947-438d-a01d-da475a1d87f3';
            case 'd3190061-17e9-ac45-9335-8fbf8ff2c558': // HBC
                return '9aeebeae-b3eb-eb55-ca48-7b76354a3801';
        }

        return $departmentId;
    }
}
