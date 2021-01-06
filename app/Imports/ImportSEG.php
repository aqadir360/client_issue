<?php

namespace App\Imports;

use App\Models\Location;
use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;

// Imports SEG pilot files
class ImportSEG implements ImportInterface
{
    private $path;

    /** @var ImportManager */
    private $import;

    private $skus;

    // Expected File Columns:
    // [0] Loc_Id
    // [1] WDCode
    // [2] UPC
    // [3] Prod_Desc
    // [4] Size_Desc
    // [5] Dept_Id
    // [6] Dept_Desc
    // [7] Price_Mult
    // [8] Price
    // [9] Pck_Num
    // [10] Avg_Dly_Units
    // [11] Avg_Dly_Lbs
    // [12] Aisle_Num
    // [13] Aisle_Side_Desc
    // [14] Planogram_Position_Ind
    // [15] Shelf
    // [16] Product_Position
    // [17] Section_Desc
    public function __construct(ImportManager $importManager)
    {
        $this->import = $importManager;
        $this->path = storage_path('imports/seg/');

        if (!file_exists($this->path)) {
            mkdir($this->path);
        }

        $this->setSkus();
    }

    public function importUpdates()
    {
        $fileList = $this->import->downloadFilesByName('SEG_DCP_Initial_');

        foreach ($fileList as $file) {
            $this->importInventory($file);
        }

        $this->import->completeImport();
    }

    private function importInventory($file)
    {
        $this->import->startNewFile($file);

        $storeNum = substr($file, -8, -4);
        $storeId = $this->import->storeNumToStoreId($storeNum);
        if ($storeId === false) {
            $this->import->completeFile();
            return;
        }

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                if ('Loc_Id' == trim($data[0])) {
                    continue;
                }

                if (!$this->import->recordRow()) {
                    continue;
                }

                $this->recordSku(trim($data[1]), trim($data[2]));

                $upc = BarcodeFixer::fixUpc(trim($data[2]));
                if ($this->import->isInvalidBarcode($upc, $data[2])) {
                    continue;
                }

                $location = $this->normalizeLocation($data);
                if ($location->valid === false) {
                    $this->import->recordSkipped();
                    continue;
                }

                $departmentId = $this->import->getDepartmentId($data[6], $data[17]);
                if ($departmentId === false) {
                    continue;
                }

                $product = $this->import->fetchProduct($upc, $storeId);

                if (!$product->isExistingProduct) {
                    $product->setDescription($data[3]);
                    $product->setSize($data[4]);

                    if (empty($product->description)) {
                        $this->import->recordFileLineError('ERROR', 'Missing Product Description');
                        continue;
                    }
                }

                if ($product->hasInventory()) {
                    $productId = $product->productId;
                    $this->import->recordStatic();
                } else {
                    $productId = $this->import->implementationScan(
                        $product,
                        $storeId,
                        $location->aisle,
                        $location->section,
                        $departmentId,
                        $location->shelf
                    );
                }

                if ($productId) {
                    $cost = 0; // Not sending cost
                    $movement = $this->import->parsePositiveFloat($data[10]);
                    $price = $this->import->parsePositiveFloat($data[8]);
                    $priceModifier = intval($data[7]);

                    $this->import->persistMetric(
                        $storeId,
                        $productId,
                        $cost,
                        $this->import->convertFloatToInt($price / $priceModifier),
                        $this->import->convertFloatToInt($movement)
                    );
                }
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }

    private function normalizeLocation(array $data): Location
    {
        $location = new Location();
        $location->aisle = trim($data[12]);
        $location->section = trim($data[13]) . trim($data[14]);
        $location->shelf = trim($data[15]);

        $location->valid = $this->shouldSkipLocation($location);

        return $location;
    }

    private function shouldSkipLocation(Location $location): bool
    {
        return !(empty($location->aisle) || intval($location->aisle) === 999 || intval($location->aisle) === 0);
    }

    private function recordSku($sku, $barcode)
    {
        if (!isset($this->skus[intval($sku)])) {
            $this->skus[intval($sku)] = $barcode;
            $this->import->db->insertSegSku($sku, $barcode);
        }
    }

    private function setSkus()
    {
        $rows = $this->import->db->fetchSegSkus();

        foreach ($rows as $row) {
            $this->skus[intval($row->sku)] = $row->barcode;
        }
    }
}
