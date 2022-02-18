<?php

namespace App\Imports;

use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;

// Sprouts Products and Metrics
class ImportSprouts implements ImportInterface
{
    /** @var ImportManager */
    private $import;

    public function __construct(ImportManager $importManager)
    {
        $this->import = $importManager;
    }

    public function importUpdates()
    {
        $files = glob(storage_path('imports/sprouts/*.csv'));

        foreach ($files as $file) {
            $this->import->startNewFile($file);

            $storeNum = $this->getStoreNum(basename($file));
            $storeId = $this->import->storeNumToStoreId($storeNum);
            if ($storeId === false) {
                $this->import->outputContent("Invalid Store $storeNum");
                return;
            }

            if (($handle = fopen($file, "r")) !== false) {
                while (($data = fgetcsv($handle, 10000, "|")) !== false) {
                    if (!$this->import->recordRow()) {
                        break;
                    }

                    $upc = $this->fixBarcode(trim($data[1]));
                    if ($this->import->isInvalidBarcode($upc, $data[1])) {
                        continue;
                    }

                    $product = $this->import->fetchProduct($upc);
                    if (!$product->isExistingProduct) {
                        $product->setDescription(str_replace('-', ' ', $data[4]));
                        $product->setSize($data[5]);
                        $this->import->createProduct($product);
                    }

                    $this->import->persistMetric(
                        $storeId,
                        $product,
                        $this->import->convertFloatToInt(floatval($data[7])),
                        $this->import->convertFloatToInt(floatval($data[6])),
                        $this->import->convertFloatToInt(floatval($data[8]))
                    );
                }

                fclose($handle);
            }

            $this->import->completeFile();
        }

        $this->import->completeImport();
    }

    private function getStoreNum($file)
    {
        $string = substr(($file), strpos(($file), '_') + 1);
        return substr($string, 0, strpos($string, '_'));
    }

    private function fixBarcode($input)
    {
        $upc = '0' . BarcodeFixer::fixUpc($input);

        if (!BarcodeFixer::isValid($upc)) {
            $upc = BarcodeFixer::fixLength($input);
        }

        return $upc;
    }
}
