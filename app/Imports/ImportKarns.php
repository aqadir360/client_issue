<?php

namespace App\Imports;

use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;

// Karns Product and Metrics Import
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
        $files = $this->import->downloadFilesByName('_load.csv');

        foreach ($files as $file) {
            $this->importProductsAndMetrics($file);
        }

        $this->import->completeImport();
    }

    private function importProductsAndMetrics($file)
    {
        $this->import->startNewFile($file);

        $storeId = $this->parseStoreNum(basename($file));
        if ($storeId === false) {
            $this->import->completeFile();
            return;
        }

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                $data = $this->parseDataRow($data);

                if (!$this->import->recordRow()) {
                    break;
                }

                $barcode = BarcodeFixer::fixUpc($data[0]);

                if (!BarcodeFixer::isValid($barcode)) {
                    $barcode = $data[0] . BarcodeFixer::calculateMod10Checksum($data[0]);
                    echo $data[0] . PHP_EOL;
                    echo $barcode . PHP_EOL;
                }

                if ($this->import->isInvalidBarcode($barcode, $data[0])) {
                    continue;
                }

                $product = $this->import->fetchProduct($barcode);
                if ($product->isExistingProduct === false) {
                    $product->setDescription($data[2]);
                    $product->setSize($data[3]);

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
                    $this->import->convertFloatToInt(floatval($data[6])),
                    $this->import->convertFloatToInt(floatval($data[5])),
                    $this->import->convertFloatToInt(floatval($data[4]) / 90)
                );
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }

    private function parseDataRow(array $row): array
    {
        $data = trim($row[0]);
        return explode('|', $data);
    }

    private function parseStoreNum($file)
    {
        $storeNum = substr($file, 5, strpos($file, '_') - 5);
        return $this->import->storeNumToStoreId($storeNum);
    }
}
