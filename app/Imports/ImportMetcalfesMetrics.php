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
class ImportMetcalfesMetrics implements ImportInterface
{
    /** @var ImportManager */
    private $import;

    public function __construct(ImportManager $importManager)
    {
        $this->import = $importManager;
    }

    public function importUpdates()
    {
        $importList = $this->import->downloadFilesByName('DCP_EXPORT_');

        foreach ($importList as $file) {
            $this->importMetricsFile($file);
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

                $barcode = BarcodeFixer::fixUpc(trim($data[1]));
                if ($this->import->isInvalidBarcode($barcode, $data[1])) {
                    continue;
                }

                $storeId = $this->import->storeNumToStoreId(intval($data[0]));
                if ($storeId === false) {
                    continue;
                }

                $cost = $this->import->parsePositiveFloat($data[5]);
                $retail = $this->import->parsePositiveFloat($data[2]);

                if ($cost > $retail) {
                    $this->import->recordSkipped();
                    continue;
                }

                $movement = $this->import->parsePositiveFloat($data[4] / 90);

                $product = $this->import->fetchProduct($barcode);
                if ($product->isExistingProduct === false) {
                    $this->import->recordSkipped();
                    continue;
                }

                $this->import->persistMetric(
                    $storeId,
                    $product->productId,
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
