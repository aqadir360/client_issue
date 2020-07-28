<?php

namespace App\Imports;

use App\Models\Location;
use App\Objects\Api;
use App\Objects\BarcodeFixer;
use App\Objects\Database;
use App\Objects\FtpManager;
use App\Objects\ImportManager;

// Downloads files added to Raleys FTP since the last import
class ImportRaleys implements ImportInterface
{
    private $companyId = 'd48c3be4-5102-1977-4c3c-2de77742dc1e';
    private $skus = [];

    /** @var ImportManager */
    private $import;

    /** @var Api */
    private $proxy;

    /** @var FtpManager */
    private $ftpManager;

    /** @var Database */
    private $db;

    public function __construct(Api $api, Database $database)
    {
        $this->proxy = $api;
        $this->db = $database;
        $this->ftpManager = new FtpManager('raleys/imports');
        $this->import = new ImportManager($database, $this->companyId);
        $this->import->setSkipList();
    }

    public function importUpdates()
    {
        $newFiles = [];
        $discoFiles = [];
        $moveFiles = [];

        $files = $this->ftpManager->getRecentlyModifiedFiles();
        foreach ($files as $file) {
            if (strpos($file, 'dcp_new_items_we') !== false || strpos($file, 'dcp_new_items_eod') !== false) {
                $newFiles[] = $this->ftpManager->downloadFile($file);
            } elseif (strpos($file, 'dcp_disc_items_we') !== false || strpos($file, 'dcp_disc_items_eod') !== false) {
                $discoFiles[] = $this->ftpManager->downloadFile($file);
            } elseif (strpos($file, 'dcp_aisle_locations_we') !== false || strpos($file, 'dcp_aisle_locations_eod') !== false) {
                $moveFiles[] = $this->ftpManager->downloadFile($file);
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

            $this->completeImport();
        }
    }

    public function completeImport(string $error = '')
    {
        $this->proxy->triggerUpdateCounts($this->companyId);
        $this->ftpManager->writeLastDate();
        $this->import->completeImport($error);
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

                $this->import->recordRow();

                $productsToImport[intval($data[1])] = [
                    trim($data[1]),
                    ucwords(strtolower(trim($data[3]))),
                    trim($data[6]),
                ];
            }

            fclose($handle);
        }

        foreach ($productsToImport as $product) {
            $upc = BarcodeFixer::fixLength($product[0]);
            if ($this->import->isInvalidBarcode($upc, $product[0])) {
                continue;
            }

            $existingProduct = $this->import->fetchProduct($upc);

            if ($existingProduct === false) {
                $response = $this->persistProduct(
                    $upc,
                    $product[1],
                    $product[2]
                );

                $this->import->recordAdd($response);
            } else {
                $this->import->currentFile->skipped++;
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

                $this->import->recordRow();

                $upc = BarcodeFixer::fixLength(trim($data[1]));
                if ($this->import->isInvalidBarcode($upc, $data[1])) {
                    continue;
                }

                $storeId = $this->import->storeNumToStoreId(trim($data[2]));
                if ($storeId === false) {
                    continue;
                }

                $response = $this->proxy->discontinueProductByBarcode($storeId, $upc);
                $this->import->recordDisco($response);
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

                $this->import->recordRow();

                $sku = intval($data[0]);
                $barcode = BarcodeFixer::fixLength($data[1]);
                if ($this->import->isInvalidBarcode($barcode, $data[1])) {
                    continue;
                }

                $storeId = $this->import->storeNumToStoreId(trim($data[2]));
                if ($storeId === false) {
                    continue;
                }

                $product = $this->import->fetchProduct($barcode, $storeId);
                if ($product->isExistingProduct === false) {
                    $this->import->currentFile->skipped++;
                    continue;
                }

                $location = $this->normalizeRaleysLocation($data[3]);
                if (!$location->valid) {
                    # these items are slated for disco and will appear in an upcoming disco file
                    $this->import->currentFile->skipped++;
                    continue;
                }

                $item = $product->getMatchingInventoryItem($location);

                if ($item !== null) {
                    if ($this->needToMoveItem($item, $location)) {
                        $response = $this->proxy->updateInventoryLocation(
                            $item->inventory_item_id,
                            $storeId,
                            $item->department_id,
                            $location->aisle,
                            $location->section
                        );
                        $this->import->recordMove($response);
                    } else {
                        $this->import->currentFile->static++;
                    }
                } else {
                    if ($this->primarySkuInventoryExists($sku, $barcode, $storeId)) {
                        $this->import->currentFile->skipped++;
                    } else {
                        if ($this->import->isInSkipList($barcode)) {
                            continue;
                        }

                        $response = $this->proxy->implementationScan(
                            $product,
                            $storeId,
                            $location->aisle,
                            $location->section,
                            $this->import->getDepartmentId('grocery')
                        );
                        $this->import->recordAdd($response);
                    }
                }
            }

            fclose($handle);
        }

        $this->import->completeFile();
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

    private function needToMoveItem($item, Location $location)
    {
        return !($item->aisle == $location->aisle && $item->section == $location->section);
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

    private function persistProduct($barcode, $name, $size)
    {
        $response = $this->proxy->persistProduct($barcode, $name, $size);

        if (!$this->proxy->validResponse($response)) {
            $this->import->addInvalidBarcode($barcode);
            $this->import->currentFile->invalidBarcodeErrors++;
            return false;
        }

        return true;
    }

    private function setSkus()
    {
        $rows = $this->db->fetchRaleysSkus();

        foreach ($rows as $row) {
            $this->skus[intval($row->sku_num)][] = [$row->barcode, intval($row->is_primary)];
        }
    }
}
