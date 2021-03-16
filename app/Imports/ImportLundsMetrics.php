<?php

namespace App\Imports;

use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;

// Lunds Metrics Import
// Expects All Items (metrics) files monthly
class ImportLundsMetrics implements ImportInterface
{
    /** @var ImportManager */
    private $import;

    public function __construct(ImportManager $importManager)
    {
        $this->import = $importManager;
        $this->import->setSkipList();
    }

    public function importUpdates()
    {
        $metricsList = $this->import->downloadFilesByName('All_Items');

        foreach ($metricsList as $filePath) {
            $this->importMetricsFile($filePath);
        }

        $this->import->completeImport();
    }

    // Expects File Format:
    // [0] STORE_ID
    // [1] UPC_EAN
    // [2] DESCRIPTION
    // [3] SELL_SIZE
    // [4] LOCATION
    // [5] Tag Quantity
    // [6] DateSTR
    // [7] Department
    // [8] Vendor_Name
    // [9] Item_Status
    // [10] Unit_Cost
    // [11] Retail
    // [12] AVERAGE_MOVEMENT
    private function importMetricsFile($file)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                if (trim($data[0]) === 'STORE_ID') {
                    continue;
                }

                if (!$this->import->recordRow()) {
                    break;
                }

                $storeId = $this->import->storeNumToStoreId($data[0]);
                if ($storeId === false) {
                    continue;
                }

                $upc = BarcodeFixer::fixLength($data[1]);
                if ($this->import->isInvalidBarcode($upc, $data[1])) {
                    continue;
                }

                if ($this->import->isInSkipList($upc)) {
                    continue;
                }

                $product = $this->import->fetchProduct($upc);
                if ($product->isExistingProduct === false) {
                    continue;
                }

                $this->import->createVendor($upc, trim($data[8]));

                if (count($data) >= 12) {
                    // sending weekly movement
                    $movement = round(floatval($data[12]) / 7, 4);

                    $this->import->persistMetric(
                        $storeId,
                        $product->productId,
                        $this->import->convertFloatToInt(floatval($data[10])),
                        $this->import->convertFloatToInt(floatval($data[11])),
                        $this->import->convertFloatToInt($movement)
                    );
                }
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }
}
