<?php

namespace App\Imports;

use App\Objects\Api;
use App\Objects\BarcodeFixer;
use App\Objects\Database;
use App\Objects\FtpManager;
use App\Objects\ImportManager;

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

    /** @var ImportManager */
    private $import;

    /** @var Api */
    private $proxy;

    /** @var FtpManager */
    private $ftpManager;

    public function __construct(Api $api, Database $database)
    {
        $this->proxy = $api;
        $this->ftpManager = new FtpManager('websters/imports', '/metrics.txt');
        $this->import = new ImportManager($database, $this->companyId);
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

            $this->import->completeImport();
        }
    }

    private function importMetricsFile($file)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                if ($data[0] === "StoreNumber") {
                    continue;
                }

                $this->import->recordRow();

                $upc = BarcodeFixer::fixUpc(trim($data[1]));
                if ($this->import->isInvalidBarcode($upc, $data[1])) {
                    continue;
                }

                $product = $this->import->fetchProduct($upc);
                if ($product->isExistingProduct === false) {
                    $this->import->currentFile->skipped++;
                    continue;
                }

                $cost = $this->import->parsePositiveFloat($data[5]);
                $retail = $this->import->parsePositiveFloat($data[2]);
                $movement = $this->import->parsePositiveFloat($data[3]);
                if ($cost > $retail) {
                    $cost = 0;
                }

                $this->import->persistMetric(
                    $this->storeId,
                    $product->productId,
                    $this->import->convertFloatToInt($cost),
                    $this->import->convertFloatToInt($retail),
                    $this->import->convertFloatToInt($movement)
                );
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }
}
