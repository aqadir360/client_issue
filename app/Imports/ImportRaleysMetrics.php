<?php

namespace App\Imports;

use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;

// Downloads metrics files added to Raleys FTP since the last import
class ImportRaleysMetrics implements ImportInterface
{
    private $unzippedPath;

    /** @var ImportManager */
    private $import;

    public function __construct(ImportManager $importManager)
    {
        $this->import = $importManager;
        $this->unzippedPath = storage_path('imports/raleys_unzipped/');
    }

    public function importUpdates()
    {
        $files = $this->import->ftpManager->getRecentlyModifiedFiles();
        foreach ($files as $file) {
            if (strpos($file, 'dcp_rate_of_sales_last_90_days') !== false) {
                $zipFile = $this->import->ftpManager->downloadFile($file);
                $this->import->ftpManager->unzipFile($zipFile, 'raleys_unzipped');
            }
        }

        $filesToImport = glob($this->unzippedPath . '*.dat');

        foreach ($filesToImport as $file) {
            $this->importRateOfSalesFile($file);
        }

        $this->import->completeImport();
    }

    private function importRateOfSalesFile($file)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                if (strpos($data[0], 'STORNBR') !== false) {
                    continue;
                }

                if (!$this->import->recordRow()) {
                    break;
                }

                $storeId = $this->import->storeNumToStoreId($data[0]);
                if ($storeId === false) {
                    continue;
                }

                $barcode = BarcodeFixer::fixUpc(trim($data[1]));
                if ($this->import->isInvalidBarcode($barcode, $data[1])) {
                    continue;
                }

                $product = $this->import->fetchProduct($barcode);
                if ($product->isExistingProduct === false) {
                    $this->import->recordSkipped();
                    continue;
                }

                $this->import->persistMetric(
                    $storeId,
                    $product,
                    $this->import->convertFloatToInt(floatval($data[3])),
                    $this->import->convertFloatToInt(floatval($data[2])),
                    $this->import->convertFloatToInt(round(floatval($data[4]) / 90)),
                    true
                );
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }
}
