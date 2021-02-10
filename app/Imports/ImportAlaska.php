<?php

namespace App\Imports;

use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;

// Alaska Commercial Product and Metrics Import
class ImportAlaska implements ImportInterface
{
    /** @var ImportManager */
    private $import;

    public function __construct(ImportManager $importManager)
    {
        $this->import = $importManager;
    }

    public function importUpdates()
    {
        $files = glob(storage_path('210126.csv'));

        foreach ($files as $file) {
            $this->importMetricsFile($file);
        }

        $this->import->completeImport();
    }

    private function importMetricsFile($file)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                if (!$this->import->recordRow()) {
                    break;
                }

                $storeNum = trim($data[0]);
                if ($storeNum != 295) {
                    continue;
                }

                $storeId = '2bc86de4-d1f1-b428-c7a8-76668c67395e';

                $barcode = $this->fixBarcode(trim($data[2]));
                if ($this->import->isInvalidBarcode($barcode, $data[2])) {
                    continue;
                }

                $dept = trim($data[6]);
                if ($dept === 'Tobacco/Accessories' || $dept === 'Beer/Wine/Liquor') {
                    continue;
                }

                $product = $this->import->fetchProduct($barcode);
                if ($product->isExistingProduct === false) {
                    $product->setDescription(trim($data[8]));
                    $product->setSize(trim($data[9]));

                    $productId = $this->import->createProduct($product);
                } else {
                    $productId = $product->productId;
                }

                if ($productId) {
                    $this->import->persistMetric(
                        $storeId,
                        $productId,
                        $this->import->convertFloatToInt($this->import->parsePositiveFloat($data[12])),
                        $this->import->convertFloatToInt($this->import->parsePositiveFloat($data[11])),
                        $this->import->convertFloatToInt($this->import->parsePositiveFloat($data[10]))
                    );
                }
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }

    private function fixBarcode($input)
    {
        if (!is_numeric($input)) {
            $this->import->recordFileLineError('Error', "Invalid Barcode $input");
            return false;
        }

        $upc = '0' . BarcodeFixer::fixUpc($input);

        if (!BarcodeFixer::isValid($upc)) {
            $upc = BarcodeFixer::fixLength($input);
        }

        return $upc;
    }
}
