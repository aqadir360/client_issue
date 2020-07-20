<?php

namespace App\Imports;

use App\Objects\Api;
use App\Objects\BarcodeFixer;
use App\Objects\ImportFtpManager;
use App\Objects\ImportStatusOutput;

// Expects file format:
// [0] - Store Number
// [1] - Barcode
// [2] - Retail
// [3] - Average Movement
// [4] - Total Movement
// [5] - Cost
class ImportWebstersMetrics implements ImportInterface
{
    private $companyId = '2719728a-a16e-ccdc-26f5-b0e9f1f23b6e';
    private $storeId = 'e3fc1cf1-3355-1a03-0684-88bec1538bf2';

    /** @var ImportStatusOutput */
    private $importStatus;

    /** @var Api */
    private $proxy;

    /** @var ImportFtpManager */
    private $ftpManager;

    public function __construct(Api $api)
    {
        $this->proxy = $api;
        $this->ftpManager = new ImportFtpManager('imports/websters/', 'websters/imports', '/metrics.txt');
        $this->importStatus = new ImportStatusOutput($this->companyId, 'Websters Metrics');
        $this->importStatus->setStores($this->proxy);
    }

    public function importUpdates()
    {
        $metricFile = null;

        $files = $this->ftpManager->getRecentlyModifiedFiles();
        foreach ($files as $file) {
            if (strpos($file, 'ITEMS') === false) {
                $metricFile = $file;
            }
        }

        if ($metricFile !== null) {
            $filePath = $this->ftpManager->downloadFile($metricFile);

            $this->importMetricsFile($filePath);

            $this->importStatus->outputResults();
        }
    }

    private function importMetricsFile($file)
    {
        $this->importStatus->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                if ($data[0] === "StoreNumber") {
                    continue;
                }

                $this->importStatus->recordRow();

                $upc = '0' . BarcodeFixer::fixUpc(trim($data[1]));
                if ($this->importStatus->isInvalidBarcode($upc)) {
                    continue;
                }

                $cost = $this->parsePositiveFloat($data[5]);
                $retail = $this->parsePositiveFloat($data[2]);
                $movement = $this->parsePositiveFloat($data[3]);
                if ($cost > $retail) {
                    $cost = 0;
                }

                $response = $this->proxy->persistMetric($upc, $this->storeId, $cost, $retail, $movement);

                if (!$this->proxy->validResponse($response) && strpos($response['message'], "Product Not Found") === false) {
                    // Unknown barcodes are expected, so only output if another error occurs
                    $this->importStatus->currentFile->skipped++;
                } else {
                    $this->importStatus->recordResult($response);
                }
            }

            fclose($handle);
        }

        $this->importStatus->completeFile();
    }

    private function parsePositiveFloat($value): float
    {
        $float = floatval($value);
        return $float < 0 ? 0 : $float;
    }
}
