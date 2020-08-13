<?php

namespace App\Imports;

use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;

// TODO: Import does not yet run automatically.  Update when file uploads start.
// Expects CSV file with format:
// [0] UPC
// [1] Department
// [2] Category
// [3] Store Num
// [4] Description
// [5] Size
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
        $files = glob($this->path . '*.csv');
        foreach ($files as $file) {
            $this->import->startNewFile($file);

            if (($handle = fopen($file, "r")) !== false) {
                while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                    if ($data[0] == 'UPC') {
                        continue;
                    }

                    if (!$this->import->recordRow()) { continue; }

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

        $this->import->completeImport();
    }
}

