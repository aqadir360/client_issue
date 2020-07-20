<?php

namespace App\Imports;

use App\Objects\Api;
use App\Objects\BarcodeFixer;
use App\Objects\ImportFtpManager;
use App\Objects\ImportStatusOutput;

// Downloads metrics files added to Raleys FTP since the last import
class ImportRaleysMetrics implements ImportInterface
{
    private $companyId = 'd48c3be4-5102-1977-4c3c-2de77742dc1e';
    private $storagePath;

    /** @var ImportStatusOutput */
    private $importStatus;

    /** @var Api */
    private $proxy;

    /** @var ImportFtpManager */
    private $ftpManager;

    public function __construct(Api $api)
    {
        $this->proxy = $api;
        $this->storagePath = storage_path('imports/raleys/');

        $this->ftpManager = new ImportFtpManager('imports/raleys/', 'raleys/imports', '/last_metrics.txt');
        $this->importStatus = new ImportStatusOutput($this->companyId, "Raleys");
    }

    public function importUpdates()
    {
        $filesToImport = [];
        $files = $this->ftpManager->getRecentlyModifiedFiles();
        foreach ($files as $file) {
            if (strpos($file, 'dcp_rate_of_sales_last_90_days') !== false) {
                $zipFile = $this->ftpManager->downloadFile($file);
                $filesToImport[] = $this->ftpManager->unzipFile($zipFile);
            }
        }

        if (count($filesToImport) > 0) {
            $this->importStatus->setStores($this->proxy);

            foreach ($filesToImport as $file) {
                $this->importRateOfSalesFile($file);
            }

            $this->ftpManager->writeLastDate();
            $this->importStatus->outputResults();
        }
    }

    private function importRateOfSalesFile($file)
    {
        $this->importStatus->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                if (strpos($data[0], 'STORNBR') !== false) {
                    continue;
                }

                $this->importStatus->recordRow();

                $storeId = $this->importStatus->storeNumToStoreId($data[0]);
                if ($storeId === false) {
                    continue;
                }

                $barcode = trim($data[1]);
                if ($this->importStatus->isInvalidBarcode($barcode)) {
                    continue;
                }

                $response = $this->proxy->persistMetric(
                    BarcodeFixer::fixUpc($barcode),
                    $storeId,
                    floatval($data[3]),
                    floatval($data[2]),
                    round(floatval($data[4]) / 90)
                );

                $this->importStatus->recordResult($response);
            }

            fclose($handle);
        }

        $this->importStatus->completeFile();
    }
}
