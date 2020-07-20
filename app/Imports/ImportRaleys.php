<?php

namespace App\Imports;

use App\Objects\Api;
use App\Objects\ImportFtpManager;
use App\Objects\ImportStatusOutput;
use Illuminate\Support\Facades\DB;

// Downloads files added to Raleys FTP since the last import
class ImportRaleys implements ImportInterface
{
    private $companyId = 'd48c3be4-5102-1977-4c3c-2de77742dc1e';
    private $skip = [];
    private $skus = [];

    /** @var ImportStatusOutput */
    private $importStatus;

    /** @var Api */
    private $proxy;

    /** @var ImportFtpManager */
    private $ftpManager;

    public function __construct(Api $api)
    {
        $this->proxy = $api;
        $this->ftpManager = new ImportFtpManager('imports/raleys/', 'raleys/imports');
        $this->importStatus = new ImportStatusOutput($this->companyId, "Raleys");

        $this->skip = $this->ftpManager->getSkipList();
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
            $this->importStatus->setStores($this->proxy);

            foreach ($newFiles as $file) {
                $this->importNewFile($file);
            }

            foreach ($discoFiles as $file) {
                $this->importDiscoFile($file);
            }

            foreach ($moveFiles as $file) {
                $this->importAisleLocationsFile($file);
            }

            $this->proxy->triggerUpdateCounts($this->companyId);
            $this->ftpManager->writeLastDate();
            $this->importStatus->outputResults();
        }
    }

    // Since new item files do not include locations, import new products only
    private function importNewFile($file)
    {
        $productsToImport = [];
        $this->importStatus->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                if (strpos($data[0], 'sku') !== false) {
                    continue;
                }

                $this->importStatus->recordRow();

                $productsToImport[intval($data[1])] = [
                    trim($data[1]),
                    ucwords(strtolower(trim($data[3]))),
                    trim($data[6]),
                ];
            }

            fclose($handle);
        }

        foreach ($productsToImport as $product) {
            $upc = $this->fixBarcode($product[0]);
            if ($this->importStatus->isInvalidBarcode($upc)) {
                continue;
            }

            $existingProduct = $this->importStatus->fetchProduct($this->proxy, $upc);

            if ($existingProduct === false) {
                $this->persistProduct(
                    $upc,
                    $product[1],
                    $product[2]
                );

                $this->importStatus->currentFile->success++;
            } else {
                $this->importStatus->currentFile->skipped++;
            }
        }

        $this->importStatus->completeFile();
    }

    private function importDiscoFile($file)
    {
        $this->importStatus->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                if (strpos($data[0], 'sku') !== false) {
                    continue;
                }

                $this->importStatus->recordRow();

                $upc = $this->fixBarcode(trim($data[1]));
                if ($this->importStatus->isInvalidBarcode($upc)) {
                    continue;
                }

                $storeId = $this->importStatus->storeNumToStoreId(trim($data[2]));
                if ($storeId === false) {
                    continue;
                }

                $response = $this->proxy->discontinueProductByBarcode($storeId, $upc);
                $this->importStatus->recordResult($response);
            }

            fclose($handle);
        }

        $this->importStatus->completeFile();
    }

    private function importAisleLocationsFile($file)
    {
        $this->importStatus->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                if (strpos($data[0], 'sku') !== false) {
                    continue;
                }

                $this->importStatus->recordRow();

                $sku = intval($data[0]);
                $barcode = $this->fixBarcode($data[1]);
                if ($this->importStatus->isInvalidBarcode($barcode)) {
                    continue;
                }

                $storeId = $this->importStatus->storeNumToStoreId(trim($data[2]));
                if ($storeId === false) {
                    continue;
                }

                $product = $this->importStatus->fetchProduct($this->proxy, $barcode, $storeId);
                if ($product === null) {
                    $this->importStatus->addInvalidBarcode($barcode);
                    continue;
                } elseif ($product === false) {
                    $this->importStatus->currentFile->skipped++;
                    continue;
                }

                $location = $this->normalizeRaleysLocation($data[3]);
                if ($location === false) {
                    # these items are slated for disco and will appear in an upcoming disco file
                    $this->importStatus->currentFile->skipped++;
                    continue;
                }

                $item = $this->getInventoryItem($product, $location);

                if ($item !== false) {
                    if ($this->needToMoveItem($item, $location)) {
                        $response = $this->proxy->updateInventoryLocation(
                            $item['inventoryItemId'],
                            $storeId,
                            $item['departmentId'],
                            $location['aisle'],
                            $location['section']
                        );

                        $this->importStatus->recordResult($response);
                    }
                } else {
                    if ($this->primarySkuInventoryExists($sku, $barcode, $storeId)) {
                        $this->importStatus->currentFile->skipped++;
                    } else {
                        $this->implementationScan(
                            $this->fixBarcode($barcode),
                            $this->getDepartmentId('GROCERY', ''),
                            $storeId,
                            $location['aisle'],
                            $location['section']
                        );
                    }
                }
            }

            fclose($handle);
        }

        $this->importStatus->completeFile();
    }

    private function primarySkuInventoryExists($sku, $barcode, $storeId): bool
    {
        // Do not import new items if the primary version of the sku is in inventory
        $primaryBarcode = $this->getPrimaryBarcode($sku);

        if ($primaryBarcode !== false && $primaryBarcode !== $barcode) {
            $existingPrimaryProduct = $this->importStatus->fetchProduct($this->proxy, $primaryBarcode, $storeId);
            if ($existingPrimaryProduct && count($existingPrimaryProduct['inventory']) > 0) {
                return true;
            }
        }

        return false;
    }

    private function needToMoveItem($item, $location)
    {
        return !($item['aisle'] == $location['aisle'] && $item['section'] == $location['section']);
    }

    private function getInventoryItem($product, $location)
    {
        if (count($product['inventory']) == 0) {
            return false;
        }

        // use exact match
        foreach ($product['inventory'] as $item) {
            if ($item['aisle'] == $location['aisle'] && $item['section'] == $location['section']) {
                return $item;
            }
        }

        // use aisle match
        foreach ($product['inventory'] as $item) {
            if ($item['aisle'] == $location['aisle']) {
                return $item;
            }
        }

        // use any non markdown section item
        foreach ($product['inventory'] as $item) {
            if ($item['aisle'] != 'MKDN') {
                return $item;
            }
        }

        return false;
    }

    private function normalizeRaleysLocation(string $input)
    {
        if (empty($input)) {
            return false;
        }

        if (strlen($input) > 0 && ($input[0] == "W" || $input[0] == "G" || $input[0] == "D")) {
            $input = substr($input, 1);
        }

        return [
            'aisle' => substr($input, 0, 2),
            'section' => strlen($input) > 2 ? substr($input, 2) : '',
        ];
    }

    private function getDepartmentId($department, $category)
    {
        switch ($department) {
            case 'GROCERY':
                if ($category == 'BABY FOOD') {
                    return '6277d3e4-c0e1-11e7-a5a1-080027c30a85'; // Baby Food
                } else {
                    return '627731b4-c0e1-11e7-9fd7-080027c30a85'; // Grocery
                }
            case 'NON FOOD GROCERY':
                return '627731b4-c0e1-11e7-9fd7-080027c30a85'; // Grocery
        }

        switch ($category) {
            case 'BABY FORMULA':
            case 'BABY FOOD':
                return '6277d3e4-c0e1-11e7-a5a1-080027c30a85'; // Baby Food
            case 'BABY HBC / OTC':
            case 'UPPER RESPIRATORY':
            case 'TOOTHPASTE':
            case 'EYE CARE':
            case 'EAR CARE':
            case 'DIGESTIVE HEALTH':
            case 'DIET NUTRITIONAL':
            case 'REFRIGERATED SUPPLEMENTS':
            case 'VITAMINS & SUPPLEMENTS':
            case 'ADULT NUTRITIONAL':
                return '62782ba0-c0e1-11e7-8257-080027c30a85'; // OTC
            default:
                $this->importStatus->addInvalidDepartment($department . " " . $category);
                return '627731b4-c0e1-11e7-9fd7-080027c30a85'; // Grocery
        }
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

    private function implementationScan($barcode, $deptId, $storeId, $aisle, $section)
    {
        if (isset($this->skip[intval($barcode)])) {
            return false;
        }

        $response = $this->proxy->implementationScan(
            $barcode,
            $storeId,
            $aisle,
            $section,
            $deptId
        );

        if (!$this->proxy->validResponse($response)) {
            $this->importStatus->addInvalidBarcode($barcode);
            return false;
        }

        return true;
    }

    private function persistProduct($barcode, $name, $size)
    {
        $response = $this->proxy->persistProduct($barcode, $name, $size);

        if (!$this->proxy->validResponse($response)) {
            $this->importStatus->addInvalidBarcode($barcode);
            $this->importStatus->currentFile->invalidBarcodeErrors++;
            return false;
        }

        return true;
    }

    private function fixBarcode($barcode)
    {
        if (strlen($barcode) == 14) {
            return substr($barcode, 1);
        }
        while (strlen($barcode) < 13) {
            $barcode = '0' . $barcode;
        }
        return $barcode;
    }

    private function setSkus()
    {
        $sql = "SELECT sku_num, barcode, is_primary FROM `raleys_products` ";
        $rows = DB::select($sql, []);

        foreach ($rows as $row) {
            $this->skus[intval($row->sku_num)][] = [$row->barcode, intval($row->is_primary)];
        }
    }
}
