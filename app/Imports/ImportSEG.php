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
    // [1] Asl_Num
    // [2] Asl_Side
    // [3] Plnogm_Pstn_Ind
    // [4] Shelf
    // [5] Prod_Pos
    // [6] Sctn_Desc
    // [7] WDCode
    // [8] UPC
    // [9] Prod_Desc
    // [10] Size_Desc
    // [11] Dept_Id
    // [12] Dept_Desc
    // [13] Price_Mult
    // [14] Price
    // [15] Pck_Num
    // [16] Avg_Dly_Units
    // [17] Avg_Dly_Lbs
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

        $storeId = $this->import->storeNumToStoreId($this->getStoreNum($file));
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

                $this->recordSku(trim($data[7]), trim($data[8]));

                $upc = BarcodeFixer::fixUpc(trim($data[8]));
                if ($this->import->isInvalidBarcode($upc, $data[8])) {
                    continue;
                }

                $location = $this->normalizeLocation($data);
                if ($location->valid === false) {
                    $this->import->recordSkipped();
                    continue;
                }

                $departmentId = $this->import->getDepartmentId($data[12], $data[6]);
                if ($departmentId === false) {
                    continue;
                }

                $product = $this->import->fetchProduct($upc, $storeId);

                if (!$product->isExistingProduct) {
                    $product->setDescription($data[9]);
                    $product->setSize($data[10]);

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
                    $movement = $this->import->parsePositiveFloat($data[16]);
                    $price = $this->import->parsePositiveFloat($data[14]);
                    $priceModifier = intval($data[13]);

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
        $location->aisle = trim($data[1]);
        $location->section = trim($data[2]) . trim($data[3]);
        $location->shelf = trim($data[4]);

        $location->valid = $this->shouldSkipLocation($location);

        return $location;
    }

    private function shouldSkipLocation(Location $location): bool
    {
        return !(empty($location->aisle) || intval($location->aisle) === 0);
    }

    private function getStoreNum(string $filename)
    {
        $start = strrpos($filename, '_');
        $end = strrpos($filename, '.');
        return substr($filename, $start + 1, $end - $start - 1);
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
