<?php

namespace App\Imports;

use App\Models\Location;
use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;
use App\Objects\InventoryCompare;

// Updates SEG Inventory Set
class ImportSEGUpdates implements ImportInterface
{
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
    // [18] Asl_Des
    // [19] DSD
    // [20] Reclaim
    // [21] Own_Brand
    public function __construct(ImportManager $importManager)
    {
        $this->import = $importManager;
        $this->setSkus();
    }

    public function importUpdates()
    {
        $fileList = $this->import->downloadFilesByName('SEG_DCP_DEV_0478_20210528');

        foreach ($fileList as $file) {
            $this->importInventory($file);
        }

        $this->import->completeImport();
    }

    private function importInventory($file)
    {
        $storeNum = $this->getStoreNum(basename($file));

        if ($storeNum === null) {
            return;
        }

        $this->import->startNewFile($file);

        $storeId = $this->import->storeNumToStoreId($storeNum);

        if ($storeId === false) {
            $this->import->completeFile();
            return;
        }

        $compare = new InventoryCompare($this->import, $storeId, 0);

        $exists = $this->setFileInventory($compare, $file, $storeId);

        if (!$exists) {
            $this->import->outputContent("Skipping $storeNum - Import file was empty");
            return;
        }

        $compare->setExistingInventory();
        $compare->compareInventorySets();

        $this->import->completeFile();
    }

    private function setFileInventory(InventoryCompare $compare, string $file, string $storeId)
    {
        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                if (trim($data[0]) === 'Loc_Id') {
                    continue;
                }

                if (count($data) < 21) {
                    //$this->import->writeFileOutput($data, "Skip: Parsing Error");
                    continue;
                }

                $sku = trim($data[7]);
                $inputBarcode = trim($data[8]);
                $upc = BarcodeFixer::fixLength($inputBarcode);
                $this->recordSku($sku, intval($inputBarcode));
                if ($this->import->isInvalidBarcode($upc, $inputBarcode)) {
                    continue;
                } else {
                    $this->recordSku($sku, intval($inputBarcode), $upc);
                }

                $product = $this->import->fetchProduct($upc);
                if (!$product->isExistingProduct) {
                    $product->setDescription($data[9]);
                    $product->setSize($data[10]);

                    $productId = $this->import->createProduct($product);

                    if ($productId === null) {
                        //$this->import->writeFileOutput($data, "Skip: Could not create product");
                        continue;
                    }

                    $product->setProductId($productId);
                }

                $location = $this->normalizeLocation($data);
                if ($location->valid === false) {
                    $this->import->writeFileOutput($data, "Skip: Invalid Location");
                    continue;
                }

                if (trim($data[19]) === 'Y') {
                    $this->import->writeFileOutput($data, "Skip: DSD Sku $sku");
                    continue;
                }

                $departmentId = $this->import->getDepartmentId($data[12], $data[6]);
                if ($departmentId === false) {
                    $this->import->writeFileOutput($data, "Skip: Invalid Department");
                    continue;
                }

                if (trim($data[20]) === 'Y') {
                    $departmentId = $this->getReclaimDepartment($departmentId);
                }

                $compare->setFileInventoryItem(
                    $upc,
                    $location->aisle,
                    $location->section,
                    $location->shelf,
                    trim($data[9]),
                    trim($data[10]),
                    $departmentId
                );

                if ($product && $product->isExistingProduct) {
                    $movement = $this->import->parsePositiveFloat($data[16]);
                    $price = $this->import->parsePositiveFloat($data[14]);
                    $priceModifier = intval($data[13]);

                    $this->import->persistMetric(
                        $storeId,
                        $product,
                        0,
                        $this->import->convertFloatToInt($price / $priceModifier),
                        $this->import->convertFloatToInt($movement)
                    );

                    if (trim($data[21]) === 'Y') {
                        $this->import->createVendor($upc, 'Own Brand');
                    }
                }
            }

            fclose($handle);
        }

        return $compare->fileInventoryCount() > 0;
    }

    private function normalizeLocation(array $data): Location
    {
        //Aisle number: Asl_des (use only last two digits), Section: Asl_Side+Plnogm_Pstn_Ind, Shelf: Shelf
        $location = new Location();
        $aisle = trim($data[18]);
        $location->aisle = trim(substr($aisle, strrpos($aisle, ' ', 1)));
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
        return intval(substr($filename, 12, strrpos($filename, '_') - 1));
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
                return '45da886a-a062-d47e-16cd-185a257c858c';
            case '5ee880ee-ac21-fcf0-9833-6cb64117f5ea': // Baby Food
                return '88ee85c9-bb73-f818-d79f-2625e8de5089';
            case 'd2135ea7-3891-d428-71b5-4af3283a5e8e': // Meat
                return '7bafcd3c-0879-6864-c134-97ec182f58e3';
        }

        return null;
    }
}
