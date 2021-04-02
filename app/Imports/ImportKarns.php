<?php

namespace App\Imports;

use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;

// Karns Product and Metrics Import
// Not yet automated: update for FTP files
class ImportKarns implements ImportInterface
{
    /** @var ImportManager */
    private $import;

    public function __construct(ImportManager $importManager)
    {
        $this->import = $importManager;
    }

    public function importUpdates()
    {
        $files = glob(storage_path('imports/karns.csv'));

        foreach ($files as $file) {
            $this->importProductsAndMetrics($file);
        }

        $this->import->completeImport();
    }

    private function importProductsAndMetrics($file)
    {
        $storeId = '562743fe-fb20-69a0-325b-8adc430375a5'; // Mechanicsburg
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                if (!$this->import->recordRow()) {
                    break;
                }

                $barcode = BarcodeFixer::fixUpc($data[0]);
                if ($this->import->isInvalidBarcode($barcode, $data[0])) {
                    continue;
                }

                $product = $this->import->fetchProduct($barcode);
                if ($product->isExistingProduct === false) {
                    $product->setDescription($data[1]);
                    $product->setSize($data[2]);

                    $productId = $this->import->createProduct($product);
                    if ($productId === null) {
                        $this->import->recordSkipped();
                        continue;
                    }

                    $product->setProductId($productId);
                }

                $this->import->persistMetric(
                    $storeId,
                    $product,
                    $this->import->convertFloatToInt(floatval($data[5])),
                    $this->import->convertFloatToInt(floatval($data[4])),
                    $this->import->convertFloatToInt(floatval($data[3]))
                );
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }
}
