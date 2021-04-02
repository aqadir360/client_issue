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
class ImportWebstersMetrics implements ImportInterface
{
    /** @var ImportManager */
    private $import;

    public function __construct(ImportManager $importManager)
    {
        $this->import = $importManager;
    }

    public function importUpdates()
    {
        $metricFile = null;

        $files = $this->import->ftpManager->getRecentlyModifiedFiles();
        foreach ($files as $file) {
            if (strpos($file, 'ITEMS') === false) {
                $metricFile = $file;
            }
        }

        if ($metricFile === null) {
            $this->import->completeImport("No new file found");
        } else {
            $filePath = $this->import->ftpManager->downloadFile($metricFile);
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

                if (!$this->import->recordRow()) {
                    break;
                }

                $storeId = $this->import->storeNumToStoreId($data[0]);
                if ($storeId === false) {
                    continue;
                }

                $upc = BarcodeFixer::fixUpc(trim($data[1]));
                if ($this->import->isInvalidBarcode($upc, $data[1])) {
                    continue;
                }

                $product = $this->import->fetchProduct($upc);
                if ($product->isExistingProduct === false) {
                    $this->import->recordSkipped();
                    continue;
                }

                $cost = $this->import->parsePositiveFloat($data[5]);
                $retail = $this->import->parsePositiveFloat($data[2]);
                $movement = $this->import->parsePositiveFloat($data[3]);
                if ($cost > $retail) {
                    $cost = 0;
                }

                $this->import->persistMetric(
                    $storeId,
                    $product,
                    $this->import->convertFloatToInt($cost),
                    $this->import->convertFloatToInt($retail),
                    $this->import->convertFloatToInt($movement),
                    true
                );
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }
}
