<?php

namespace App\Imports;

use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;

// Imports Caputos products
class ImportCaputos implements ImportInterface
{
    /** @var ImportManager */
    private $import;

    public function __construct(ImportManager $importManager)
    {
        $this->import = $importManager;
    }

    public function importUpdates()
    {
        $storeId = '4f74e46e-3412-e2a2-d910-beeee10df32d';

        $fileList = glob(storage_path('imports/caputos_movement.csv'));

        foreach ($fileList as $file) {
            $this->importMetrics($file, $storeId);
        }

        $this->import->completeImport();
    }

    private function importMetrics($file, $storeId)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                $upc = BarcodeFixer::fixUpc(trim($data[0]));
                if ($this->import->isInvalidBarcode($upc, $data[0])) {
                    echo $data[0] . PHP_EOL;
                    continue;
                }

                $product = $this->import->fetchProduct($upc);

                if ($product->isExistingProduct) {
                    $this->import->persistMetric(
                        $storeId,
                        $product,
                        0,
                        0,
                        $this->import->convertFloatToInt(floatval($data[1])),
                        true
                    );
                }
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }
}
