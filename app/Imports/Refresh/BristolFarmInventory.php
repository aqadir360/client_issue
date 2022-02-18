<?php

namespace App\Imports\Refresh;

use App\Imports\ImportInterface;
use App\Models\Location;
use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;
use App\Objects\InventoryCompare;
use Illuminate\Support\Facades\Log;

// [0] Store
// [1] UPC
// [2] Product Name
// [3] Size
// [4] Section Name
// [5] Category
// [6] Area_Aisle
// [7] Movement
// [8] Price($)
// [9] Cost($)

class BristolFarmInventory implements ImportInterface
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
        $files = glob(storage_path('imports\bristolfarms\*.psv'));

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

                $upc = BarcodeFixer::fixUpc($data[1]);

                if ($this->import->isInvalidBarcode($upc, $data[1])) {
                    $this->import->writeFileOutput($data, "Skip: Invalid Barcode");
                    continue;
                }

                $product = $this->import->fetchProduct($upc);

                if ($product->isExistingProduct === false) {
                    $product->setDescription(trim($data[2]));
                    $product->setSize(trim($data[3]));
                    $productId = $this->import->createProduct($product);

                    if ($productId) {
                        $product->setProductId($productId);
                    } else {
                        $this->import->writeFileOutput($data, "Skip: Invalid Product");
                        continue;
                    }
                }

                $location = $this->parseLocation($data);

                if ($location->valid === false) {
                    $this->import->recordSkipped();
                    $this->import->writeFileOutput($data, "Skip: Invalid Location");
                    continue;
                }

                $departmentId = $this->import->getDeptIdAndRecordCategory($product, trim($data[5]), trim($data[4]));

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
                    $movement = is_numeric($data[7]) ? (intval($data[7]) / 90) : 0;

                    $this->import->persistMetric(
                        $storeId,
                        $product,
                        $this->import->convertFloatToInt(floatval($data[9])),
                        $this->import->convertFloatToInt(floatval($data[8])),
                        $this->import->convertFloatToInt($movement),
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

    private function parseLocation(array $data): Location
    {
        $location = new Location();

        if (!empty($data[6])) {
            $trimData = trim($data[6]);
            preg_match('/s*(\d+)/', $trimData, $matches, PREG_OFFSET_CAPTURE);
            $location->aisle = substr($trimData, 0, $matches[0][1] + 1);
            $location->section = substr($trimData, $matches[0][1] + 1);
            $location->valid = true;
        }

        return $location;
    }
}
