<?php

namespace App\Imports;

use App\Models\Location;
use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;

// Imports SEG pilot files
class ImportSEG implements ImportInterface
{
    /** @var ImportManager */
    private $import;

    private $skus;
    private $reclaim;

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
        $this->setSkus();
        $this->setReclaimSkus();
    }

    public function importUpdates()
    {
        $fileList = $this->import->downloadFilesByName('SEG_DCP_Initial_20210304_');

        foreach ($fileList as $file) {
            $this->importInventory($file);
        }

        $this->import->completeImport();
    }

    private function importInventory($file)
    {
        $storeNum = $this->getStoreNum($file);
        if ($storeNum === null) {
            return;
        }

        $this->import->startNewFile($file);

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

                if (count($data) < 8) {
                    $this->import->writeFileOutput($data, "Skip: Parsing Error");
                    $this->import->recordFileLineError('ERROR', 'Unable to parse row: ' . json_encode($data));
                    continue;
                }

                $sku = trim($data[7]);
                $inputBarcode = intval(trim($data[8]));
                $upc = BarcodeFixer::fixUpc($inputBarcode);
                if ($this->import->isInvalidBarcode($upc, $inputBarcode)) {
                    $this->recordSku(trim($data[7]), $inputBarcode);
                    $this->import->writeFileOutput($data, "Skip: Invalid Barcode");
                    continue;
                } else {
                    $this->recordSku($sku, $inputBarcode, $upc);
                }

                $location = $this->normalizeLocation($data);
                if ($location->valid === false) {
                    $this->import->recordSkipped();
                    $this->import->writeFileOutput($data, "Skip: Invalid Location");
                    continue;
                }

                $departmentId = $this->import->getDepartmentId($data[12], $data[6]);
                if ($departmentId === false) {
                    $this->import->writeFileOutput($data, "Skip: Invalid Department");
                    continue;
                }

                if (isset($this->reclaim[intval($sku)])) {
                    $departmentId = $this->getReclaimDepartment($departmentId);
                }

                $product = $this->import->fetchProduct($upc, $storeId);

                if (!$product->isExistingProduct) {
                    $product->setDescription($data[9]);
                    $product->setSize($data[10]);

                    if (empty($product->description)) {
                        $this->import->writeFileOutput($data, "Skip: Missing Description for New Product");
                        $this->import->recordFileLineError('ERROR', 'Missing Product Description');
                        continue;
                    }
                }

                if ($product->hasInventory()) {
                    $productId = $product->productId;
                    $this->import->recordStatic();
                    $this->import->writeFileOutput($data, "Success: Inventory Exists");
                } else {
                    $productId = $this->import->implementationScan(
                        $product,
                        $storeId,
                        $location->aisle,
                        $location->section,
                        $departmentId,
                        $location->shelf
                    );
                    $this->import->writeFileOutput($data, "Success: Created Inventory");
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
        $filename = str_replace('_updated', '', $filename);
        $start = strrpos($filename, '_');
        $end = strrpos($filename, '.');
        return substr($filename, $start + 1, $end - $start - 1);
    }

    private function recordSku($sku, $inputBarcode, $barcode = null)
    {
        if (!isset($this->skus[intval($sku)])) {
            $this->skus[intval($sku)] = $inputBarcode;
            $this->import->db->insertSegSku($sku, $inputBarcode, $barcode);
        }
    }

    private function setSkus()
    {
        $rows = $this->import->db->fetchSegSkus();

        foreach ($rows as $row) {
            $this->skus[intval($row->sku)] = $row->barcode;
        }
    }

    private function setReclaimSkus()
    {
        $rows = $this->import->db->fetchSegReclaimSkus();

        foreach ($rows as $row) {
            $this->reclaim[intval($row->sku)] = $row->sku;
        }
    }

    private function getReclaimDepartment(string $departmentId): ?string
    {
        switch ($departmentId) {
            case '2178be72-a05e-b7a0-06d6-2840b1c1c4a9': // Dairy
            case 'a93ece5d-b3be-31ed-9bec-0d7f70cab852': // Yogurt
            case 'b04d4e3e-f189-3fda-2cca-ba074e70bf6f': // Yogurt (Mkdn)
            case 'ba39d8bf-be93-3857-ddd2-2aec9d06302b': // Yogurt (Rotate)
                return '1430f863-5f2b-eaed-e235-588bd3d2246a';
            case '3b31ed22-5c5e-4c27-591a-9891f0e696ed': // Grocery
            case '82d915ec-b904-66bf-c0b0-fcc29df22101': // Short Life
            case '5ee880ee-ac21-fcf0-9833-6cb64117f5ea': // Baby Food
                return '45da886a-a062-d47e-16cd-185a257c858c';
            case 'd2135ea7-3891-d428-71b5-4af3283a5e8e': // Meat
                return '7bafcd3c-0879-6864-c134-97ec182f58e3';
        }

        return null;
    }
}
