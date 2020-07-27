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
class ImportHansensMetrics implements ImportInterface
{
    private $companyId = '61ef52da-c0e1-11e7-a59b-080027c30a85';

    /** @var ImportManager */
    private $import;

    /** @var FtpManager */
    private $ftpManager;

    /** @var Api */
    private $proxy;

    public function __construct(Api $api, Database $database)
    {
        $this->proxy = $api;
        $this->ftpManager = new FtpManager('hansens/imports');
        $this->import = new ImportManager($database, $this->companyId);
    }

    public function importUpdates()
    {
        $newestFile = $this->ftpManager->getMostRecentFile();

        if ($newestFile !== null) {
            $filePath = $this->ftpManager->downloadFile($newestFile);
            $this->importMetricsFile($filePath);
        }

        $this->import->completeImport();
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

                $barcode = '0' . BarcodeFixer::fixUpc(trim($data[1]));
                if ($this->import->isInvalidBarcode($barcode)) {
                    continue;
                }

                $storeId = $this->import->storeNumToStoreId(
                    $this->inputNumToStoreNum(trim($data[0]))
                );
                if ($storeId === false) {
                    continue;
                }

                $cost = $this->parsePositiveFloat($data[5]);
                $retail = $this->parsePositiveFloat($data[2]);

                if ($cost > $retail) {
                    $this->import->currentFile->skipped++;
                    continue;
                }

                $this->persistMetric($barcode, $storeId, $cost, $retail);
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }

    private function persistMetric($barcode, $storeId, $cost, $retail)
    {
        // TODO: temporarily skipping movement values
        $movement = 0;

        $response = $this->proxy->persistMetric($barcode, $storeId, $cost, $retail, $movement);
        $this->import->recordMetric($response);
    }

    private function inputNumToStoreNum($input)
    {
        switch ($input) {
            case '001':
                return '9169';
            case '002':
                return '9174';
            case '003':
                return '9170';
            case '004':
                return '9166';
            case '005':
                return '9172';
            case '006':
                return '9173';
            case '007':
                return '9171';
            case '008':
                return '9167';
            case '009':
                return '9168';
            case '010':
                return '9605';
            case '011':
                return '9606';
            case '012':
                return '9600';
        }

        return $input;
    }

    private function parsePositiveFloat($value): float
    {
        $float = floatval($value);
        return $float < 0 ? 0 : $float;
    }
}
