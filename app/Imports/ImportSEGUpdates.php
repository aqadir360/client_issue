<?php

namespace App\Imports;

use App\Models\Location;
use App\Models\Product;
use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;
use App\Objects\InventoryCompare;

// Updates SEG Inventory Set
class ImportSEGUpdates implements ImportInterface
{
    /** @var ImportManager */
    private $import;

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
    // [13] Price_Mult - mislabled, reversed with 14
    // [14] Price
    // [15] Pck_Num
    // [16] Avg_Dly_Units
    // [17] Avg_Dly_Lbs
    // [18] Asl_Des
    // [19] DSD
    // [20] Reclaim
    // [21] Own_Brand
    // [22] Item_Status (O = Inactive, D = Disco, A = Active)
    // [23] SubCategory

    public function __construct(ImportManager $importManager)
    {
        $this->import = $importManager;
        $this->import->setCategories();
    }

    public function importUpdates()
    {
        $files = $this->getStoreFiles();

        foreach ($files as $storeNum => $file) {
            $this->importInventory($file, $storeNum);
        }

        $this->import->completeImport();
    }

    private function getStoreFiles(): array
    {
        $storeFiles = [];
        $stores = $this->import->getStores();
        $files = $this->import->ftpManager->getRecentlyModifiedFiles();

        foreach ($stores as $storeNum => $store) {
            $file = $this->import->downloadMostRecentMatchingFile($files, 'SEG_DCP_' . $storeNum . '_');
            if (!is_null($file)) {
                $storeFiles[$storeNum] = $file;
            }
        }

        return $storeFiles;
    }

    private function importInventory(string $file, int $storeNum)
    {
        $this->import->startNewFile($file);

        $storeId = $this->import->storeNumToStoreId($storeNum);

        if ($storeId === false) {
            $this->import->completeFile();
            return;
        }

        $compare = new InventoryCompare($this->import, $storeId);

        $total = $this->setFileInventory($compare, $file, $storeId);

        if ($total <= 0) {
            $this->import->outputContent("Skipping $storeNum - Import file was empty");
            return;
        }

        $this->import->setTotalCount($total);
        $this->import->outputAndResetFile();

        $compare->setExistingInventory();
        $compare->compareInventorySets(true, false);

        $this->import->completeFile();
    }

    private function setFileInventory(InventoryCompare $compare, string $file, string $storeId): int
    {
        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 10000, "|")) !== false) {
                if (trim($data[0]) === 'Loc_Id') {
                    continue;
                }

                if (!$this->import->recordRow()) {
                    continue;
                }

                if (count($data) < 21) {
                    $this->import->writeFileOutput($data, "Skip: Parsing Error");
                    continue;
                }

                $product = $this->getOrCreateProduct($data);
                if ($product === null) {
                    continue;
                }

                $location = $this->normalizeLocation($data);
                if ($location->valid === false) {
                    $this->import->writeFileOutput($data, "Skip: Invalid Location");
                    continue;
                }

                if ($this->isExcludedLocation(strtolower($location->aisle))) {
                    $this->import->writeFileOutput($data, "Skip: Excluded Location");
                    continue;
                }

                if (intval($data[19]) === 1) {
                    $this->import->writeFileOutput($data, "Skip: DSD Sku");
                    continue;
                }

                $this->import->recordCategory($product, $this->parseCategory(trim($data[6])), trim($data[23]));

                $departmentId = $this->import->getDepartmentId(
                    trim(strtolower($data[12])),
                    trim(strtolower($data[23])),
                    $product->barcode
                );

                if ($departmentId === false) {
                    $this->import->writeFileOutput($data, "Skip: Invalid Department");
                    continue;
                }

                if (intval($data[20]) === 1) {
                    $departmentId = $this->getReclaimDepartment($departmentId);
                }

                $status = trim($data[22]);
                if ($status === 'D') {
                    $this->import->writeFileOutput($data, "Skip: D Status");
                    continue;
                } elseif ($status === 'O') {
                    // Do not discontinue or add
                    $location->valid = false;
                }

                $compare->setFileInventoryItem(
                    $product,
                    $location,
                    $departmentId
                );

                $movement = $this->import->parsePositiveFloat($data[16]);
                $price = $this->import->parsePositiveFloat($data[13]);
                $priceModifier = intval($data[14]);
                if ($priceModifier <= 0) {
                    $price = 0;
                } else {
                    $price = $price / $priceModifier;
                }

                $this->import->persistMetric(
                    $storeId,
                    $product,
                    $this->import->convertFloatToInt($price), // clone retail to cost
                    $this->import->convertFloatToInt($price),
                    $this->import->convertFloatToInt($movement)
                );

                if (trim($data[21]) === 'Y') {
                    $this->import->createVendor($product, 'Own Brand');
                } else {
                    $this->import->createVendor($product, 'None');
                }

                $this->import->writeFileOutput($data, "Success: Valid Product");
            }

            fclose($handle);
        }

        return $compare->fileInventoryCount();
    }

    private function getOrCreateProduct(array $data): ?Product
    {
        $inputBarcode = trim($data[8]);
        $upc = BarcodeFixer::fixLength($inputBarcode);
        if ($this->import->isInvalidBarcode($upc, $inputBarcode)) {
            return null;
        }

        return $this->import->getOrCreateProduct($upc, $data[9], $data[10], trim($data[7]));
    }

    private function normalizeLocation(array $data): Location
    {
        // Aisle number: Asl_des (use only last two digits if containing prefix Aisle)
        // Section: Asl_Side+Plnogm_Pstn_Ind, Shelf: Shelf
        $location = new Location();

        $aisle = trim($data[18]);
        if (strpos($aisle, 'Aisle') !== false) {
            $location->aisle = trim(substr($aisle, strrpos($aisle, ' ', 1)));
        } else {
            $location->aisle = trim($aisle);
        }

        // Prepend zero to single digit aisles
        if (strlen($location->aisle) === 1 && is_numeric($location->aisle) && $location->aisle !== '0') {
            $location->aisle = '0' . $location->aisle;
        }

        $location->section = strtoupper(trim($data[2])) . trim($data[3]);
        $location->shelf = trim($data[4]);

        $location->valid = $this->isValidLocation($location);

        return $location;
    }

    private function isValidLocation(Location $location): bool
    {
        if (empty($location->aisle) || $location->aisle === "0") {
            return false;
        }

        if (strtoupper($location->aisle) === 'NA') {
            return false;
        }

        return true;
    }

    private function isExcludedLocation(string $aisle): bool
    {
        $excluded = [
            "99",
            "baglc",
            "beers",
            "cust",
            "dollr",
            "endcp",
            "gmhbc",
            "hbcgm",
            "liqur",
            "natrl",
            "naturl",
            "pharm",
            "promo",
            "racks",
            "rgstr",
            "shell",
            "supbr",
            "test",
            "wallv",
            "wines",
        ];

        foreach ($excluded as $str) {
            if (strpos($aisle, $str) !== false) {
                return true;
            }
        }

        return false;
    }

    private function getStoreNum(string $filename)
    {
        return intval(substr($filename, 8, strrpos($filename, '_') - 1));
    }

    private function getReclaimDepartment(string $departmentId): string
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
            case 'b6a2e154-98b6-4643-85ea-fb2c93edcd23': // Formula
                return '1e54a0fc-8a9c-54e7-7252-05403c708cce';
            case 'd2135ea7-3891-d428-71b5-4af3283a5e8e': // Meat
                return '7bafcd3c-0879-6864-c134-97ec182f58e3';
        }

        return $departmentId;
    }

    private function parseCategory($input): string
    {
        // Remove everything after _WD
        $pos = strpos($input, '_WD');
        if ($pos !== false) {
            $input = substr($input, 0, $pos);
        }

        // Remove everything after WD_
        $pos = strpos($input, 'WD_');
        if ($pos !== false) {
            $input = substr($input, 0, $pos);
        }

        // Remove everything after strings of format <int><int>X<int><int>
        $positions = $this->strposAll($input, 'X');
        foreach ($positions as $pos) {
            if ($pos !== false && $pos > 3 && $pos < strlen($input) - 2) {
                if ((is_numeric($input[$pos - 2]) && is_numeric($input[$pos - 1]))
                    && (is_numeric($input[$pos + 2]) && is_numeric($input[$pos + 1]))) {
                    $input = substr($input, 0, $pos - 3);
                }
            }
        }

        return $input;
    }

    private function strposAll(string $haystack, string $needle): array
    {
        $offset = 0;
        $all = [];
        while (($pos = strpos($haystack, $needle, $offset)) !== false) {
            $offset = $pos + 1;
            $all[] = $pos;
        }
        return $all;
    }
}
