<?php

namespace App\Imports;

use App\Objects\ImportManager;

// Mayville Pig Product and Metrics Import
class ImportMayville implements ImportInterface
{
    /** @var ImportManager */
    private $import;

    public function __construct(ImportManager $importManager)
    {
        $this->import = $importManager;
    }

    public function importUpdates()
    {
        // TODO: get this from file if provided
        $storeId = '87e732b2-08f8-041c-1f11-1fd2c2b4d7f1'; // Mayville Piggly Wiggly

        $metricsList = glob(storage_path('imports/mayville.csv'));

        foreach ($metricsList as $filePath) {
            $this->importMetricsFile($filePath, $storeId);
        }

        $this->import->completeImport();
    }

    private function importMetricsFile(string $file, string $storeId)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                if (!$this->import->recordRow()) {
                    break;
                }

                // Metrics barcodes include check digit
                $barcode = str_pad(ltrim(trim($data[0]), '0'), 13, '0', STR_PAD_LEFT);
                if ($this->import->isInvalidBarcode($barcode, $data[0])) {
                    continue;
                }

                $product = $this->import->fetchProduct($barcode);
                if ($product->isExistingProduct === false) {
                    $product->setDescription($data[1]);
                    $product->setSize($data[2]);
                    echo $barcode . PHP_EOL;
                    $this->import->createProduct($product);
                }

                $this->import->persistMetric(
                    $storeId,
                    $product,
                    $this->import->convertFloatToInt(floatval($data[4])),
                    $this->import->convertFloatToInt(floatval($data[3])),
                    $this->import->convertFloatToInt(floatval($data[5])),
                    true
                );
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }
}
