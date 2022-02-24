<?php

namespace App\Imports\Refresh;

use App\Imports\ImportInterface;
use App\Models\Location;
use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;
use App\Objects\InventoryCompare;

// [0] Store
// [1] UPC
// [2] Department
// [3] Category
// [4] Description
// [5] Size
// [6] Price
// [7] Cost
// [8] 90_Day_AVG_Daily_Movement


class SproutsInventory implements ImportInterface
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
        $files = glob(storage_path('imports\sprouts\*.csv'));

        foreach ($files as $file) {
            $this->importInventory($file);
        }

        $this->import->completeImport();
    }

    private function importInventory($file)
    {
        $storeNum = $this->getStoreNum(basename($file));
        $storeId = $this->import->storeNumToStoreId($storeNum);
        if ($storeId === false) {
            return;
        }

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
            while (($data = fgetcsv($handle, 10000, "|")) !== false) {
                if ($this->import->recordRow() === false) {
                    continue;
                }

                $upc = $this->fixBarcode(trim($data[1]));

                if ($this->import->isInvalidBarcode($upc, $data[1])) {
                    $this->import->writeFileOutput($data, "Skip: Invalid Barcode");
                    continue;
                }

                $product = $this->import->fetchProduct($upc);

                if ($product->isExistingProduct === false) {
                    $product->setDescription(str_replace('-', ' ', $data[4]));
                    $product->setSize(trim($data[5]));
                    $productId = $this->import->createProduct($product);

                    if ($productId) {
                        $product->setProductId($productId);
                    } else {
                        $this->import->writeFileOutput($data, "Skip: Invalid Product");
                        continue;
                    }
                }

                $location = new Location();

                $departmentId = $this->import->getDeptIdAndRecordCategory($product, trim($data[2]), trim($data[3]));

                if ($departmentId === false) {
                    $this->import->writeFileOutput($data, "Skip: Invalid Department");
                    continue;
                }

                if ($storeId == '862fc0dd-8f7f-e681-5d40-78aabbd2c012') {
                    $departmentId = $this->getStoreWiseDDepartment($departmentId);
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
                        $this->import->convertFloatToInt(floatval($data[7])),
                        $this->import->convertFloatToInt(floatval($data[6])),
                        $this->import->convertFloatToInt(floatval($data[8])),
                    );

                } else {
                    $this->import->writeFileOutput($data, "Skipped: New Product");
                }
            }

            fclose($handle);
        }

        return $compare->fileInventoryCount() > 0;
    }

       private function getStoreNum($file)
    {
        $string = substr(($file), strpos(($file), '_') + 1);
        return substr($string, 0, strpos($string, '_'));
    }

    private function fixBarcode($input)
    {
        $upc = '0' . BarcodeFixer::fixUpc($input);

        if (!BarcodeFixer::isValid($upc)) {
            $upc = BarcodeFixer::fixLength($input);
        }

        return $upc;
    }

    private function getStoreWiseDDepartment(string $departmentId): string
    {
        switch ($departmentId) {
            case '11301709-5372-8791-f967-11fa0a9ce474':
                return '0d8ee77b-9b79-eb9d-5836-95901a9cd653';
            case '63a648be-9a6c-5d00-4e04-bd34e2a57fde':
                return '7a2d4294-2197-7bd8-99ba-b5bccc89fd3e';
            case 'ce8e4a5d-d701-8dba-6beb-2b314d3a852b':
                return '990d9253-12cf-efac-c6ec-d31ffe161fd7';
            case '30b98ae9-871a-ade6-4eff-d1748418fd4c':
                return '50046be2-08a1-1a31-360f-61dc5451ca61';
            case '7cd8dd03-40c0-178c-d278-70f552e97401':
                return '9026fb59-1292-59f6-debb-7aa5d6504b72';
            case 'eb62185d-2af4-8ec6-5fa2-de3994aa5a87':
                return '1eb5e4e1-d66a-aed5-ba50-a50e679e8be4';
        }

        return $departmentId;
    }



}
