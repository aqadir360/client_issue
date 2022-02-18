<?php

namespace App\Imports;

use App\Imports\Settings\PriceChopperSettings;
use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;

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
class ImportPriceChopper implements ImportInterface
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
        $files = glob(storage_path('imports/price_chopper.csv'));

        foreach ($files as $file) {
            $this->importInventory($file);
        }

        $this->import->completeImport();
    }

    private function importInventory($file)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 2000, ",")) !== false) {
                if (!$this->import->recordRow()) {
                    continue;
                }

                $storeId = $this->import->storeNumToStoreId(trim($data[0]));
                if (!$storeId) {
                    continue;
                }

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

                $this->import->implementationScan(
                    $product,
                    $storeId,
                    $location->aisle,
                    $location->section,
                    $departmentId,
                    $location->shelf,
                    true
                );

                $this->import->writeFileOutput($data, "Success: Created");

                $this->import->persistMetric(
                    $storeId,
                    $product,
                    $this->import->convertFloatToInt(floatval($data[10])),
                    $this->import->convertFloatToInt(floatval($data[9])),
                    $this->import->convertFloatToInt(floatval($data[7])),
                );
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }
}
