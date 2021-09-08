<?php

namespace App\Imports;

use App\Models\Location;
use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;

// [0] Store
// [1] SKU
// [2] UPC
// [3] Dept
// [4] Category
// [5] Product Description
// [6] Product Size
// [7] Avg 90 Day Sales
// [8] Total 90 Day Sales
// [9] Avg Retl
// [10] Avg Cost
// [11] Aisle
// [12] Side
// [13] Section X-Coord
// [14] Section Y-Coord
// [15] Item Position
class ImportPriceChopper implements ImportInterface
{
    private $products = [];
    private $skus = [];

    /** @var ImportManager */
    private $import;

    public function __construct(ImportManager $importManager)
    {
        $this->import = $importManager;
        $this->import->setSkipList();
    }

    public function importUpdates()
    {
        $files = glob(storage_path('imports/price_chopper.csv'));

        $this->setSkus();

        foreach ($files as $file) {
            $this->importInventory($file);
        }

        $this->import->completeImport();
    }

    private function importInventory($file)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 2000, ",")) !== false) {
                if (!$this->import->recordRow()) {
                    continue;
                }

                $storeId = $this->import->storeNumToStoreId(trim($data[0]));
                if (!$storeId) {
                    continue;
                }

                $upc = BarcodeFixer::fixUpc($data[2]);
                $this->recordSku(intval($data[1]), $upc);
                if ($this->import->isInvalidBarcode($upc, $data[2])) {
                    continue;
                }

                $location = $this->parseLocation($data);
                if ($location->valid === false) {
                    $this->import->recordSkipped();
                    $this->import->writeFileOutput($data, "Skip: Invalid Location");
                    continue;
                }

                $departmentId = $this->import->getDepartmentId(trim(strtolower($data[3])), trim(strtolower($data[4])), $upc);
                if ($departmentId === false) {
                    $this->import->writeFileOutput($data, "Skip: Invalid Department");
                    continue;
                }

                $product = $this->import->fetchProduct($upc, $storeId);
                if ($product->isExistingProduct === false) {
                    $this->import->recordSkipped();
                    $this->import->writeFileOutput($data, "Skip: Missing Product");
                    continue;
                }

                if ($product->hasInventory()) {
                    $item = $product->getMatchingInventoryItem($location, $departmentId);

                    if ($this->needToMoveItem($item, $location)) {
                        $this->import->updateInventoryLocation(
                            $item->inventory_item_id,
                            $storeId,
                            $departmentId,
                            $location->aisle,
                            $location->section,
                            $location->shelf
                        );
                        $this->import->writeFileOutput($data, "Success: Moved Inventory");
                    } else {
                        $this->import->recordStatic();
                        $this->import->writeFileOutput($data, "Static: Existing Inventory");
                    }
                } else {
                    $this->import->implementationScan(
                        $product,
                        $storeId,
                        $location->aisle,
                        $location->section,
                        $departmentId,
                        $location->shelf,
                        true
                    );

                    $this->import->writeFileOutput($data, "Success: Created Inventory");
                }

                $this->import->persistMetric(
                    $storeId,
                    $product,
                    $this->import->convertFloatToInt(floatval($data[10])),
                    $this->import->convertFloatToInt(floatval($data[9])),
                    $this->import->convertFloatToInt(floatval($data[7])),
                );
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }

    private function needToMoveItem($item, Location $location): bool
    {
        return !($item->aisle === $location->aisle
            && $item->section === $location->section
            && $item->shelf === $location->shelf);
    }

    private function parseLocation(array $data)
    {
        $aisle = trim($data[11]);

        // Include full aisle string when not beginning with AL (e.g. AL01 becomes 01, RX01 remains RX01).
        if (strpos($aisle, 'AL') === 0) {
            $aisle = substr($aisle, 2);
        }

        // Use the first character of Left or Right
        $side = trim($data[12]);
        if (strlen($side) > 1) {
            $side = substr($side, 0, 1);
        }

        // Plus the integer value of Y-Coord without rounding
        $decimal = strpos(trim($data[14]), ".");
        $position = ($decimal === false) ? trim($data[14]) : substr(trim($data[14]), 0, $decimal);

        $shelf = trim($data[15]);
        $location = new Location($aisle, $side . $position, $shelf);

        // Skip blank aisles.
        if (!empty($location->aisle)) {
            $location->valid = true;
        }

        return $location;
    }

    private function setSkus()
    {
        $rows = $this->import->db->fetchPriceChopperSkus();

        foreach ($rows as $row) {
            $this->skus[intval($row->sku_num)][] = [$row->barcode];
        }
    }

    private function recordSku($sku, $barcode)
    {
        if (!isset($this->skus[intval($sku)])) {
            $this->skus[intval($sku)] = $barcode;
            $this->import->db->insertPriceChopperSku($sku, $barcode);
        }
    }
}
