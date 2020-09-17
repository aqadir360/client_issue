<?php

namespace App\Imports;

use App\Objects\BarcodeFixer;
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
    /** @var ImportManager */
    private $import;

    public function __construct(ImportManager $importManager)
    {
        $this->import = $importManager;
    }

    public function importUpdates()
    {
        $newestFile = $this->import->ftpManager->getMostRecentFile();

        if ($newestFile !== null) {
            $filePath = $this->import->ftpManager->downloadFile($newestFile);
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

                if (!$this->import->recordRow()) {
                    break;
                }

                $barcode = '0' . BarcodeFixer::fixUpc(trim($data[1]));
                if ($this->import->isInvalidBarcode($barcode, $data[1])) {
                    continue;
                }

                $storeId = $this->import->storeNumToStoreId($this->inputNumToStoreNum(trim($data[0])));
                if ($storeId === false) {
                    continue;
                }

                $cost = $this->import->parsePositiveFloat($data[5]);
                $retail = $this->import->parsePositiveFloat($data[2]);

                if ($cost > $retail) {
                    $this->import->recordSkipped();
                    continue;
                }

                $product = $this->import->fetchProduct($barcode);
                if ($product->isExistingProduct === false) {
                    $this->import->recordSkipped();
                    continue;
                }

                // TODO: temporarily skipping movement values
                $movement = 0;

                $this->import->persistMetric(
                    $storeId,
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
}
