<?php

namespace App\Imports;

use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;

// Imports Janssens products and metrics
class ImportJanssens implements ImportInterface
{
    /** @var ImportManager */
    private $import;

    public function __construct(ImportManager $importManager)
    {
        $this->import = $importManager;
    }

    public function importUpdates()
    {
        $fileList = $this->import->downloadFilesByName('JANSSENS');

        foreach ($fileList as $file) {
            $this->importProducts($file);
        }

        $this->import->completeImport();
    }

    private function importProducts($file)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                if (!$this->import->recordRow()) {
                    continue;
                }

                $storeId = $this->import->storeNumToStoreId(intval(trim($data[0])));
                if (!$storeId) {
                    continue;
                }

                if (count($data) < 8) {
                    $this->import->writeFileOutput($data, "Skip: Parsing Error");
                    $this->import->recordFileLineError('ERROR', 'Unable to parse row: ' . json_encode($data));
                    continue;
                }

                $upc = BarcodeFixer::fixUpc(trim($data[1]));
                if ($this->import->isInvalidBarcode($upc, $data[1])) {
                    $this->import->writeFileOutput($data, "Skip: Invalid Barcode");
                    continue;
                }

                $product = $this->import->fetchProduct($upc);

                if (!$product->isExistingProduct) {
                    $product->setDescription($data[2]);
                    $product->setSize($this->parseSize(trim($data[2]), trim($data[3])));

                    if (empty($product->description)) {
                        $this->import->writeFileOutput($data, "Skip: Missing Description for New Product");
                        $this->import->recordFileLineError('ERROR', 'Missing Product Description');
                        continue;
                    }
                }

                $productId = $this->import->createProduct($product);
                $this->import->writeFileOutput($data, "Success: Created Product");

                if ($productId) {
                    $retail = $this->import->parsePositiveFloat($data[9]);
                    if (isset($data[30])) {
                        $movement = $this->import->parsePositiveFloat($data[30] / 45);
                    } else {
                        $movement = 0;
                        $this->import->writeFileOutput($data, "Error: Movement Value");
                    }
                    $cost = $this->import->parsePositiveFloat($this->parseCost($data[11]));

                    $this->import->persistMetric(
                        $storeId,
                        $productId,
                        $this->import->convertFloatToInt($cost),
                        $this->import->convertFloatToInt($retail),
                        $this->import->convertFloatToInt($movement)
                    );
                }
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }

    private function parseSize(string $full, string $desc)
    {
        return trim(substr($full, strlen($desc)));
    }

    private function parseCost(string $input)
    {
        $values = explode(',', $input);
        return $values[0];
    }
}
