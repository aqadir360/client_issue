<?php

namespace App\Imports;

use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;

// TODO: Import does not yet run automatically.  Update when file uploads start.
class ImportSEG implements ImportInterface
{
    private $path;

    /** @var ImportManager */
    private $import;

    public function __construct(ImportManager $importManager)
    {
        $this->import = $importManager;
        $this->path = storage_path('imports/seg/');

        if (!file_exists($this->path)) {
            mkdir($this->path);
        }
    }

    public function importUpdates()
    {
        $metricsList = $this->import->downloadFilesByName('SEG_DCP_Initial_20201105');

        foreach ($metricsList as $file) {
            $this->importMetrics($file);
        }

        $this->import->completeImport();
    }

    private function importMetrics($file)
    {
        $this->import->startNewFile($file);

        $storeNum = substr($file, -8, -4);
        $storeId = $this->import->storeNumToStoreId($storeNum);
        if ($storeId === false) {
            $this->import->completeFile();
            return;
        }

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                if ('Loc_Id' == trim($data[0])) {
                    continue;
                }

                if (!$this->import->recordRow()) {
                    continue;
                }

                $upc = BarcodeFixer::fixUpc(trim($data[2]));
                if ($this->import->isInvalidBarcode($upc, $data[2])) {
                    continue;
                }

                $product = $this->import->fetchProduct($upc);

                if ($product->isExistingProduct) {
                    $cost = 0; // Not sending cost
                    $movement = $this->import->parsePositiveFloat($data[10]);
                    $price = $this->import->parsePositiveFloat($data[8]);
                    $priceModifier = intval($data[7]);

                    $this->import->persistMetric(
                        $storeId,
                        $product->productId,
                        $cost,
                        $this->import->convertFloatToInt($price / $priceModifier),
                        $this->import->convertFloatToInt($movement),
                        true
                    );
                } else {
                    $this->import->recordSkipped();
                }
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }

    private function importLocalFiles()
    {
        $files = glob($this->path . '*.csv');
        foreach ($files as $file) {
            $this->import->startNewFile($file);

            if (($handle = fopen($file, "r")) !== false) {
                while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                    if ($data[0] == 'UPC') {
                        continue;
                    }

                    if (!$this->import->recordRow()) {
                        continue;
                    }

                    $storeId = $this->import->storeNumToStoreId($data[3]);
                    if ($storeId === false) {
                        continue;
                    }

                    $departmentId = $this->import->getDepartmentId($data[1]);
                    if ($departmentId === false) {
                        continue;
                    }

                    $upc = BarcodeFixer::fixUpc(trim($data[0]));
                    $product = $this->import->fetchProduct($upc, $storeId);

                    if ($product->isExistingProduct && !$product->hasInventory()) {
                        $product->setDescription($data[4]);
                        $product->setSize($data[5]);

                        $this->import->implementationScan(
                            $product,
                            $storeId,
                            "UNKN",
                            "",
                            $departmentId
                        );
                    } else {
                        $this->import->recordSkipped();
                    }
                }

                fclose($handle);
            }

            $this->import->completeFile();
        }
    }
}

