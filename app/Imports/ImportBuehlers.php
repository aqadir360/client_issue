<?php

namespace App\Imports;

use App\Objects\Api;
use App\Objects\BarcodeFixer;
use App\Objects\Database;
use App\Objects\FtpManager;
use App\Objects\ImportManager;

// Buehler's Inventory Import
// Expects Disco and Active Items files weekly
class ImportBuehlers implements ImportInterface
{
    private $companyId = 'e0700753-8b88-c1b6-8cc9-1613b7154c7e';

    /** @var ImportManager */
    private $import;

    /** @var Api */
    private $proxy;

    /** @var FtpManager */
    private $ftpManager;

    public function __construct(Api $api, Database $database)
    {
        $this->proxy = $api;
        $this->ftpManager = new FtpManager('buehler/imports');
        $this->import = new ImportManager($database, $this->companyId);
        $this->import->setSkipList();
    }

    public function importUpdates()
    {
        $discoFiles = [];
        $activeFiles = [];

        $files = $this->ftpManager->getRecentlyModifiedFiles();
        foreach ($files as $file) {
            if (strpos($file, 'Disc') !== false) {
                $discoFiles[] = $this->ftpManager->downloadFile($file);
            } else {
                $activeFiles[] = $this->ftpManager->downloadFile($file);
            }
        }

        foreach ($discoFiles as $file) {
            $this->importDiscoFile($file);
        }

        foreach ($activeFiles as $file) {
            $this->importActiveFile($file);
        }

        $this->proxy->triggerUpdateCounts($this->companyId);
        $this->ftpManager->writeLastDate();
        $this->import->completeImport();
    }

    private function importDiscoFile($file)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                $this->import->recordRow();

                $upc = BarcodeFixer::fixUpc(trim($data[2]));
                if ($this->import->isInvalidBarcode($upc, $data[2])) {
                    continue;
                }

                $storeId = $this->import->storeNumToStoreId($data[1]);
                if ($storeId === false) {
                    continue;
                }

                $product = $this->import->fetchProduct($upc);
                if ($product->isExistingProduct) {
                    $response = $this->proxy->discontinueProduct($storeId, $product->productId);
                    $this->import->recordDisco($response);
                } else {
                    $this->import->currentFile->skipped++;
                }
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }

    private function importActiveFile($file)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                $this->import->recordRow();

                $upc = BarcodeFixer::fixLength($data[1]);
                if ($this->import->isInvalidBarcode($upc, $data[2])) {
                    continue;
                }

                $storeId = $this->import->storeNumToStoreId($data[0]);
                if ($storeId === false) {
                    continue;
                }

                $departmentId = $this->import->getDepartmentId($data[2], $data[3]);
                if ($departmentId === false) {
                    continue;
                }

                if ($this->import->isInSkipList($upc)) {
                    continue;
                }

                $product = $this->import->fetchProduct($upc, $storeId);

                if ($product->isExistingProduct && $product->hasInventory()) {
                    $this->import->currentFile->skipped++;
                    continue;
                }

                $product->setDescription($data[4]);
                $product->setSize($data[5]);

                $response = $this->proxy->implementationScan(
                    $product,
                    $storeId,
                    'UNKN',
                    '',
                    $departmentId
                );
                $this->import->recordAdd($response);
                $this->persistMetric($upc, $storeId, $data);
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }

    private function persistMetric($upc, $storeId, $row)
    {
        $product = $this->import->fetchProduct($upc);
        if ($product->isExistingProduct === false) {
            return;
        }

        $this->import->persistMetric(
            $storeId,
            $product->productId,
            $this->import->convertFloatToInt(floatval($row[7])),
            $this->import->convertFloatToInt(floatval($row[6])),
            $this->import->convertFloatToInt(floatval($row[8]))
        );
    }
}
