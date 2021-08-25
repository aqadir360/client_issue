<?php

namespace App\Imports;

use App\Models\Location;
use App\Models\Product;
use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;

// Downloads files added to Raleys FTP since the last import
class ImportRaleys implements ImportInterface
{
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
        $newFiles = [];
        $discoFiles = [];
        $moveFiles = [];

        $files = $this->import->ftpManager->getRecentlyModifiedFiles();
        foreach ($files as $file) {
            if (strpos($file, 'dcp_new_items_we') !== false || strpos($file, 'dcp_new_items_eod') !== false) {
                $newFiles[] = $this->import->ftpManager->downloadFile($file);
            } elseif (strpos($file, 'dcp_disc_items_we') !== false || strpos($file, 'dcp_disc_items_eod') !== false) {
                $discoFiles[] = $this->import->ftpManager->downloadFile($file);
            } elseif (strpos($file, 'dcp_aisle_locations_we') !== false
                || strpos($file, 'dcp_aisle_locations_eod') !== false
                || strpos($file, 'dcp_aisle_locations_108_424_eod') !== false
            ) {
                $moveFiles[] = $this->import->ftpManager->downloadFile($file);
            }
        }

        if (count($newFiles) > 0 || count($discoFiles) > 0 || count($moveFiles) > 0) {
            $this->setSkus();

            foreach ($newFiles as $file) {
                $this->importNewFile($file);
            }

            foreach ($discoFiles as $file) {
                $this->importDiscoFile($file);
            }

            foreach ($moveFiles as $file) {
                $this->importAisleLocationsFile($file);
            }
        }

        $this->import->completeImport();
    }

    // Since new item files do not include locations, import new products only
    private function importNewFile($file)
    {
        $productsToImport = [];
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                if (strpos($data[0], 'sku') !== false) {
                    continue;
                }

                $productsToImport[intval($data[1])] = [
                    trim($data[1]),
                    ucwords(strtolower(trim($data[3]))),
                    trim($data[6]),
                ];
            }

            fclose($handle);
        }

        foreach ($productsToImport as $productData) {
            if (!$this->import->recordRow()) {
                continue;
            }

            $upc = BarcodeFixer::fixLength($productData[0]);
            if ($this->import->isInvalidBarcode($upc, $productData[0])) {
                continue;
            }

            $product = $this->import->fetchProduct($upc);

            if (!$product->isExistingProduct) {
                $product->setDescription($productData[1]);
                $product->setSize($productData[2]);
                $response = $this->import->createProduct($product);
                $this->import->recordResponse(!empty($response), 'add');
            } else {
                $this->import->recordSkipped();
            }
        }

        $this->import->completeFile();
    }

    private function importDiscoFile($file)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                if (strpos($data[0], 'sku') !== false) {
                    continue;
                }

                if (!$this->import->recordRow()) {
                    break;
                }

                $upc = BarcodeFixer::fixLength(trim($data[1]));
                if ($this->import->isInvalidBarcode($upc, $data[1])) {
                    $this->import->writeFileOutput($data, "Skip: Invalid Barcode");
                    continue;
                }

                $storeId = $this->import->storeNumToStoreId(trim($data[2]));
                if ($storeId === false) {
                    $this->import->writeFileOutput($data, "Skip: Invalid Store");
                    continue;
                }

                $this->import->discontinueProductByBarcode($storeId, $upc);
                $this->import->writeFileOutput($data, "Success: Discontinued");
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }

    private function importAisleLocationsFile($file)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                if (strpos($data[0], 'sku') !== false) {
                    continue;
                }

                if (!$this->import->recordRow()) {
                    break;
                }

                $sku = intval($data[0]);
                $barcode = BarcodeFixer::fixLength($data[1]);
                if ($this->import->isInvalidBarcode($barcode, $data[1])) {
                    $this->import->writeFileOutput($data, "Skip: Invalid Barcode");
                    continue;
                }

                $storeId = $this->import->storeNumToStoreId(trim($data[2]));
                if ($storeId === false) {
                    $this->import->writeFileOutput($data, "Skip: Invalid Store");
                    continue;
                }

                $product = $this->import->fetchProduct($barcode, $storeId);
                if ($product->isExistingProduct === false) {
                    $this->import->recordSkipped();
                    $this->import->writeFileOutput($data, "Skip: New Product");
                    continue;
                }

                $location = $this->normalizeRaleysLocation($data[3]);
                if (!$location->valid) {
                    # these items are slated for disco and will appear in an upcoming disco file
                    $this->import->writeFileOutput($data, "Skip: Invalid Location");
                    $this->import->recordSkipped();
                    continue;
                }

                $departmentId = $this->import->getDepartmentId(trim($data[6]), trim($data[7]));
                if (!$departmentId) {
                    $this->import->writeFileOutput($data, "Skip: Invalid Department");
                    continue;
                }

                $item = $product->getMatchingInventoryItem($location);

                if ($item !== null) {
                    if ($this->needToMoveItem($item, $location, $departmentId)) {
                        $this->moveInventoryItem(
                            $item->inventory_item_id,
                            $storeId,
                            $departmentId,
                            $location->aisle,
                            $location->section,
                            $data
                        );
                    } else {
                        $this->import->writeFileOutput($data, "Static: Existing Inventory");
                        $this->import->recordStatic();
                    }
                } else {
                    if ($this->primarySkuInventoryExists($sku, $barcode, $storeId)) {
                        $this->import->writeFileOutput($data, "Skip: Primary SKU Exists");
                        $this->import->recordSkipped();
                    } else if ($this->import->isInSkipList($barcode)) {
                        $this->import->writeFileOutput($data, "Skip: Skip List");
                    } else {
                        $this->createInventory($product, $storeId, $location, $departmentId, $data);
                    }
                }
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }

    private function createInventory(Product $product, $storeId, Location $location, $departmentId, $data)
    {
        $success = $this->import->implementationScan(
            $product,
            $storeId,
            $location->aisle,
            $location->section,
            $departmentId
        );

        if (!is_null($success)) {
            $this->import->writeFileOutput($data, "Success: Created Inventory");
        } else {
            $this->import->writeFileOutput($data, "Error: Could Not Create Inventory");
        }
    }

    private function moveInventoryItem($itemId, $storeId, $departmentId, $aisle, $section, $data)
    {
        $success = $this->import->updateInventoryLocation(
            $itemId,
            $storeId,
            $departmentId,
            $aisle,
            $section
        );

        if ($success) {
            $this->import->writeFileOutput($data, "Success: Updated Location $aisle $section");
        } else {
            $this->import->writeFileOutput($data, "Error: Could Not Update Location");
        }
    }

    private function primarySkuInventoryExists($sku, $barcode, $storeId): bool
    {
        // Do not import new items if the primary version of the sku is in inventory
        $primaryBarcode = $this->getPrimaryBarcode($sku);

        if ($primaryBarcode !== false && $primaryBarcode !== $barcode) {
            $existing = $this->import->fetchProduct($primaryBarcode, $storeId);
            return ($existing->isExistingProduct && $existing->hasInventory());
        }

        return false;
    }

    private function needToMoveItem($item, Location $location, string $departmentId)
    {
        return !($item->aisle === $location->aisle
            && $item->section === $location->section
            && $item->department_id === $departmentId
        );
    }

    private function normalizeRaleysLocation(string $input): Location
    {
        if (empty($input)) {
            return new Location();
        }

        if (strlen($input) > 0 && ($input[0] == "W" || $input[0] == "G" || $input[0] == "D")) {
            $input = substr($input, 1);
        }

        $location = new Location();
        $location->aisle = substr($input, 0, 2);
        $location->section = strlen($input) > 2 ? substr($input, 2) : '';
        $location->valid = true;
        return $location;
    }

    private function getPrimaryBarcode($skuNum)
    {
        if (isset($this->skus[$skuNum])) {
            $skus = $this->skus[$skuNum];

            foreach ($skus as $sku) {
                if ($sku[1] === 1) {
                    return $sku[0];
                }
            }
        }

        return false;
    }

    private function setSkus()
    {
        $rows = $this->import->db->fetchRaleysSkus();

        foreach ($rows as $row) {
            $this->skus[intval($row->sku_num)][] = [$row->barcode, intval($row->is_primary)];
        }
    }
}
