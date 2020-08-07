<?php

namespace App\Imports;

use App\Objects\Api;
use App\Objects\BarcodeFixer;
use App\Objects\Database;
use App\Objects\FtpManager;
use App\Objects\ImportManager;

// Downloads metrics files added to Raleys FTP since the last import
class ImportRaleysMetrics implements ImportInterface
{
    private $companyId = 'd48c3be4-5102-1977-4c3c-2de77742dc1e';
    private $unzippedPath;

    /** @var ImportManager */
    private $import;

    /** @var Api */
    private $proxy;

    /** @var FtpManager */
    private $ftpManager;

    public function __construct(Api $api, Database $database)
    {
        $this->proxy = $api;
        $this->ftpManager = new FtpManager('raleys/imports', '/last_metrics.txt');
        $this->import = new ImportManager($database, $this->companyId);
        $this->unzippedPath = storage_path('imports/raleys_unzipped/');
    }

    public function importUpdates()
    {
        $files = $this->ftpManager->getRecentlyModifiedFiles();
        foreach ($files as $file) {
            if (strpos($file, 'dcp_rate_of_sales_last_90_days') !== false) {
                $zipFile = $this->ftpManager->downloadFile($file);
                $this->ftpManager->unzipFile($zipFile, 'raleys_unzipped');
            }
        }

        $filesToImport = glob($this->unzippedPath . '*.dat');

        foreach ($filesToImport as $file) {
            $this->importRateOfSalesFile($file);
        }

        $this->completeImport();
    }

    public function completeImport(string $error = '')
    {
        $this->ftpManager->writeLastDate();
        $this->import->completeImport($error);
    }

    private function importRateOfSalesFile($file)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                if (strpos($data[0], 'STORNBR') !== false) {
                    continue;
                }

                $this->import->recordRow();

                $storeId = $this->import->storeNumToStoreId($data[0]);
                if ($storeId === false) {
                    continue;
                }

                $barcode = BarcodeFixer::fixUpc(trim($data[1]));
                if ($this->import->isInvalidBarcode($barcode, $data[1])) {
                    continue;
                }

                $product = $this->import->fetchProduct($barcode);
                if ($product->isExistingProduct === false) {
                    $this->import->currentFile->skipped++;
                    continue;
                }

                $success = $this->import->persistMetric(
                    $storeId,
                    $product->productId,
                    $this->import->convertFloatToInt(floatval($data[3])),
                    $this->import->convertFloatToInt(floatval($data[2])),
                    $this->import->convertFloatToInt(round(floatval($data[4]) / 90))
                );

                if ($success) {
                    $this->import->recordMetric($success);
                } else {
                    $this->import->currentFile->skipped++;
                }
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }
}
