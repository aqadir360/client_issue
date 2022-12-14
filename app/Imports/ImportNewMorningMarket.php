<?php

namespace App\Imports;

use App\Models\Location;
use App\Models\Product;
use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;
use App\Objects\InventoryCompare;

// Updates New Morning Market Inventory by comparison
class ImportNewMorningMarket implements ImportInterface
{
    /** @var ImportManager */
    private $import;

    // Expected Columns:
    // [0] UPC/PLU
    // [1] Qty Sold
    // [2] Department
    // [3] Price
    // [4] Unit Cost
    // [5] Size
    // [6] Long Description
    // [7] Brand
    // [8] Aisle
    // [9] Shelf
    // [10] Cur Price Qty
    // [11] Cur Price
    // [12] Base Un Cst
    // [13] Category Name
    // [14] Credited Item
    // [15] DateCheckPro Exception
    public function __construct(ImportManager $importManager)
    {
        $this->import = $importManager;
        $this->import->setSkipList();
    }

    public function importUpdates()
    {
        // TODO: get value from file if provided
        $storeId = '9662b68e-bb14-11eb-af4c-080027af75ff';

        $newestFile = $this->import->ftpManager->getMostRecentFile();
        $file = $this->import->ftpManager->downloadFile($newestFile);

        if ($file) {
            $this->importInventory($file, $storeId);
        }

        $this->import->completeImport();
    }

    private function importInventory(string $file, string $storeId)
    {
        $this->import->startNewFile($file);

        $compare = new InventoryCompare($this->import, $storeId);

        $exists = $this->setFileInventory($compare, $file, $storeId);

        if (!$exists) {
            $this->import->outputContent("Skipping - Import file was empty");
            return;
        }

        $this->import->outputAndResetFile();

        $compare->setExistingInventory();
        $compare->compareInventorySets();

        $this->import->completeFile();
    }

    private function setFileInventory(InventoryCompare $compare, string $file, string $storeId)
    {
        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 10000, "|")) !== false) {
                if (trim($data[0]) === 'UPC/PLU') {
                    continue;
                }

                if (!$this->import->recordRow()) {
                    continue;
                }

                $inputBarcode = trim($data[0]);
                $upc = BarcodeFixer::fixUpc($inputBarcode);
                if ($this->import->isInvalidBarcode($upc, $inputBarcode)) {
                    continue;
                }

                if ($this->import->isInSkipList($upc)) {
                    $this->import->writeFileOutput($data, 'Skip: In Skip List');
                    continue;
                }

                $product = $this->import->fetchProduct($upc);
                if (!$product->isExistingProduct) {
                    $product->setDescription($data[6]);
                    $product->setSize($data[5]);

                    $productId = $this->import->createProduct($product);

                    if ($productId === null) {
                        $this->import->writeFileOutput($data, "Skip: Could not create product");
                        continue;
                    }

                    $product->setProductId($productId);
                }

                $location = $this->normalizeLocation($data);
                if (!$location->valid) {
                    $this->import->writeFileOutput($data, "Skip: Invalid Location");
                    continue;
                }

                $departmentId = $this->import->getDeptIdAndRecordCategory($product, trim($data[2]), trim($data[13]));

                if ($departmentId === false) {
                    $this->import->writeFileOutput($data, "Skip: Invalid Department");
                    continue;
                }

                if (strtolower(trim($data[14])) === 'y') {
                    $departmentId = $this->mapCreditDepartment($departmentId);
                }

                if (strtolower(trim($data[15])) === 'y') {
                    $this->import->writeFileOutput($data, "Skip: Exception Item");
                    continue;
                }

                $compare->setFileInventoryItem(
                    $product,
                    $location,
                    $departmentId
                );

                $this->createMetric($storeId, $product, $data);
                $this->import->writeFileOutput($data, "Success: Valid Product");
            }

            fclose($handle);
        }

        return $compare->fileInventoryCount() > 0;
    }

    private function createMetric(string $storeId, Product $product, array $data)
    {
        $movement = $this->import->parsePositiveFloat($data[1]) / 90;
        $retail = $this->import->parsePositiveFloat($data[3]);
        $cost = $this->import->parsePositiveFloat($data[4]);

        $this->import->persistMetric(
            $storeId,
            $product,
            $this->import->convertFloatToInt($cost),
            $this->import->convertFloatToInt($retail),
            $this->import->convertFloatToInt($movement)
        );
    }

    private function normalizeLocation(array $data): Location
    {
        // maintain full aisle string, excluding spaces
        $location = new Location(trim($data[8]), trim($data[9]));
        $location->valid = true;
        return $location;
    }

    private function mapCreditDepartment(string $departmentId): string
    {
        switch ($departmentId) {
            case '80072628-c9a9-e6a4-ec11-469828c77439': // Dairy
                return '5e6ed52e-1d73-11ed-a115-0022484b8b22';
            case 'c70e3308-bb14-11eb-9086-080027af75ff': // Grocery
                return '79d88b70-1d73-11ed-8ebf-0022484b8b22';
            case 'd10d8383-95a8-b0d5-48b5-b8d4e89e5c9f': // Meat
                return 'cecc9072-1d73-11ed-8bca-0022484b8b22';
        }

        return $departmentId;
    }
}
