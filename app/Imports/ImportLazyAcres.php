<?php

namespace App\Imports;

use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;

// Requires local file
// Imports products and metrics
class ImportLazyAcres implements ImportInterface
{
    /** @var ImportManager */
    private $import;

    public function __construct(ImportManager $importManager)
    {
        $this->import = $importManager;
    }

    public function importUpdates()
    {
        $files = glob(storage_path('imports/25.csv'));

        foreach ($files as $file) {
            $this->importProductsAndMetrics($file);
        }

        $this->import->completeImport();
    }

    private function importProductsAndMetrics($file)
    {
        // TODO: remove hard coding
        $storeId = '585ab82a-87e1-64e7-4ba5-27416cdf0929'; // #25
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                if (!$this->import->recordRow()) {
                    break;
                }

                $barcode = BarcodeFixer::fixUpc(trim($data[1]));
                if ($this->import->isInvalidBarcode($barcode, $data[1])) {
                    continue;
                }

                $product = $this->import->fetchProduct($barcode);
                if ($product->isExistingProduct === false) {
                    $product->setDescription(trim($data[6]));
                    $product->setSize(trim($data[7]));
                    $productId = $this->import->createProduct($product);

                    if ($productId === null) {
                        continue;
                    }
                }

                $cost = $this->import->parsePositiveFloat($data[10]);
                $retail = $this->import->parsePositiveFloat($data[9]);

                if ($cost > $retail) {
                    $cost = 0;
                }

                $movement = $this->import->parsePositiveFloat(floatval($data[8]));

                $this->import->persistMetric(
                    $storeId,
                    $product,
                    $this->import->convertFloatToInt($cost),
                    $this->import->convertFloatToInt($retail),
                    $this->import->convertFloatToInt($movement),
                    false
                );
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }
}
