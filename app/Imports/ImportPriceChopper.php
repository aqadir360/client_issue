<?php

namespace App\Imports;

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
class ImportPriceChopper implements ImportInterface
{
    private $products = [];
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
        $files = glob(storage_path('imports/price_chopper.csv'));

        $this->setSkus();

        foreach ($files as $file) {
            $this->importMetrics($file);
        }

        $this->import->completeImport();
    }

    private function importMetrics($file)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                if (strpos($data[0], 'Store') !== false) {
                    continue;
                }

                if (!$this->import->recordRow()) {
                    continue;
                }

                $storeId = $this->import->storeNumToStoreId(trim($data[0]));
                if (!$storeId) {
                    continue;
                }

                $upc = BarcodeFixer::fixUpc($data[2]);
                $this->recordSku(intval($data[1]), $upc);
                if ($this->import->isInvalidBarcode($upc, $data[2])) {
                    continue;
                }

                $product = $this->getOrCreateProduct($upc, $data);

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

    private function getOrCreateProduct($upc, $data)
    {
        if (isset($this->products[intval($upc)])) {
            return $this->products[intval($upc)];
        }

        $product = $this->import->fetchProduct($upc);

        if (!$product->isExistingProduct) {
            echo $upc . PHP_EOL;
            $product->setDescription($data[5]);
            $product->setSize($data[6]);
            $productId = $this->import->createProduct($product);
            $product->setProductId($productId);
        }

        $this->products[intval($upc)] = $product;

        return $product;
    }

    private function importProducts($file)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                if (strpos($data[0], 'Store') !== false) {
                    continue;
                }

                if (!$this->import->recordRow()) {
                    continue;
                }

                $upc = BarcodeFixer::fixUpc($data[2]);
                $this->recordSku(intval($data[1]), $upc);
                if ($this->import->isInvalidBarcode($upc, $data[2])) {
                    continue;
                }

                $product = $this->import->fetchProduct($upc);

                if (!$product->isExistingProduct) {
                    echo $upc . PHP_EOL;
                    $product->setDescription($data[5]);
                    $product->setSize($data[6]);
                    $response = $this->import->createProduct($product);
                    $this->import->recordResponse(!empty($response), 'add');
                } else {
                    $this->import->recordSkipped();
                }
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }

    private function setSkus()
    {
        $rows = $this->import->db->fetchPriceChopperSkus();

        foreach ($rows as $row) {
            $this->skus[intval($row->sku_num)][] = [$row->barcode];
        }
    }

    private function recordSku($sku, $barcode)
    {
        if (!isset($this->skus[intval($sku)])) {
            $this->skus[intval($sku)] = $barcode;
            $this->import->db->insertPriceChopperSku($sku, $barcode);
        }
    }
}
