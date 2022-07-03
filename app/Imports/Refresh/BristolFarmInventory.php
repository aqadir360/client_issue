<?php

namespace App\Imports\Refresh;

use App\Imports\ImportInterface;
use App\Models\Location;
use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;
use App\Objects\InventoryCompare;

// [0] Store
// [1] UPC
// [2] Aisle
// [3] Section
// [4] Department
// [5] Category
// [6] Description
// [7] Size
// [8] Movement
// [9] Price($)
// [10] Cost($)

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
        $files = $this->import->downloadFilesByName('DateCheckPro_FileExport_');

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
            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
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

                $departmentId = $this->import->getDeptIdAndRecordCategory($product, trim($data[4]), trim($data[5]));

                if ($departmentId === false) {
                    $this->import->writeFileOutput($data, "Skip: Invalid Department");
                    continue;
                }

                $compare->setFileInventoryItem(
                    $product,
                    $location,
                    $departmentId
                );

                if ($product->isExistingProduct) {
                    $movement = is_numeric($data[8]) ? (intval($data[8]) / 90) : 0;

                    $this->import->persistMetric(
                        $storeId,
                        $product,
                        $this->import->convertFloatToInt(floatval($data[10])),
                        $this->import->convertFloatToInt(floatval($data[9])),
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

    private function getStoreNum(string $filename): int
    {
        return intval(substr($filename, strrpos($filename, '_') - 4, 4));
    }

    private function parseLocation(array $data): Location
    {
        $location = new Location(trim($data[2]), trim($data[3]));

        // skip any aisle with an "RG" prefix, and skip any aisle with an "OT" prefix
        if (strpos($location->aisle, 'RG') || strpos($location->aisle, 'OT')) {
            return $location;
        }

        if (!empty($location->aisle)) {
            $location->valid = true;
        }

        return $location;
    }
}
