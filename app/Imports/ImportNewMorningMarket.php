<?php

namespace App\Imports;

use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;

// Requires local file
class ImportNewMorningMarket implements ImportInterface
{
    /** @var ImportManager */
    private $import;

    public function __construct(ImportManager $importManager)
    {
        $this->import = $importManager;
    }

    public function importUpdates()
    {
        $files = glob(storage_path('nmm_products.csv'));

        foreach ($files as $file) {
            $this->importProductsAndMetrics($file);
        }

        $this->import->completeImport();
    }

    private function importProductsAndMetrics($file)
    {
        // TODO: remove hard coding
        $storeId = '9662b68e-bb14-11eb-af4c-080027af75ff';
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                if (!$this->import->recordRow()) {
                    break;
                }

                $barcode = BarcodeFixer::fixUpc(trim($data[0]));
                if ($this->import->isInvalidBarcode($barcode, $data[0])) {
                    continue;
                }

                $product = $this->import->fetchProduct($barcode);
                if ($product->isExistingProduct === false) {
                    $product->setDescription(trim($data[7]));
                    $product->setSize(trim($data[6]));

                    $productId = $this->import->createProduct($product);
                    if (!$productId) {
                        continue;
                    } else {
                        $product->setProductId($productId);
                    }
                }

                $cost = $this->import->parsePositiveFloat($data[5]);
                $retail = $this->import->parsePositiveFloat($data[4]);

                if ($cost > $retail) {
                    $cost = 0;
                }

                $movement = $this->import->parsePositiveFloat(floatval($data[2]));

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
