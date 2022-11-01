<?php

namespace App\Imports\Refresh;

use App\Imports\ImportInterface;
use App\Models\Location;
use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;
use App\Objects\InventoryCompare;



// [0] UPC
// [1] Aisle
// [2] Sect
// [3] Shelf
// [4] Dept
// [5] Description
// [6] Size
// [7] Avg 90
// [8] Retail
// [9] Cost

// Column A – UPC
// Column B – Skip
// Column C – Skip
// Column D – Skip
// Column E – Department
// Column F – Item Description
// Column G – Item Size
// Column H – Average Movement Per Day
// Column I – Retail Price
// Column J – Cost of Item

class MaurersInventory implements ImportInterface
{
    /** @var ImportManager */
    private $import;

    public function __construct(ImportManager $importManager)
    {
        $this->import = $importManager;
        $this->import->setSkipList();
    }

    public function importUpdates()
    {

        $files = $this->import->ftpManager->getRecentlyModifiedFiles();

        foreach ($files as $file) {

            $zipFile = $this->import->ftpManager->downloadFile($file);
            $this->import->ftpManager->unzipFile($zipFile, 'maurers_unzipped');

        }

        $filesToImport = glob(storage_path('imports/maurers_unzipped/*'));

        foreach ($filesToImport as $file) {
            $this->importInventory($file);
        }

        $this->import->completeImport();
    }

    private function importInventory($file)
    {
        $storeId = '6d2644e0-3576-6357-32ec-84f4e25a8d0d';
        if ($storeId === false) {
            return;
        }

        $compare = new InventoryCompare($this->import, $storeId);

        $this->import->startNewFile($file);
        $this->setFileInventory($compare, $file, $storeId); // check file for all data , valid inve
        $compare->setExistingInventory(); // fetch all exitsitng
        $compare->compareInventorySets(false,false); // compare and create new, move and discontinue
        $this->import->completeFile();
    }

    private function setFileInventory(InventoryCompare $compare, string $file, string $storeId)
    {
        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                if ($this->import->recordRow() === false) {
                    continue;
                }

                $upc = BarcodeFixer::fixLength(trim($data[0]));

                if ($this->import->isInvalidBarcode($upc, trim($data[0]))) {
                    $this->import->writeFileOutput($data, "Skip: Invalid Barcode");
                    continue;
                }

                $movement = floatval($data[7]);
                if ($movement <= 0) {
                    $this->import->writeFileOutput($data, "Skip: Invalid Movement");
                    continue;
                }

                $product = $this->import->fetchProduct($upc);

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

                $location = new Location();
                $location->valid = true;

                $departmentId = $this->import->getDepartmentId(trim(strtolower($data[4])), '', $product->barcode);

                if ($departmentId === false) {
                    $this->import->writeFileOutput($data, "Skip: Invalid Department");
                    continue;
                }

                $compare->setFileInventoryItem(
                    $product,
                    $location,
                    $departmentId
                );

                $this->import->persistMetric(
                    $storeId,
                    $product,
                    $this->import->convertFloatToInt(floatval($data[9])),
                    $this->import->convertFloatToInt(floatval($data[8])),
                    $this->import->convertFloatToInt(floatval($data[7])),
                );

                if ($location->valid) {
                    $this->import->writeFileOutput($data, "Success: Valid Product");
                } else {
                    $this->import->writeFileOutput($data, "Skipped: Invalid Location");
                }

            }

            fclose($handle);
        }

        return $compare->fileInventoryCount() > 0;
    }


}
