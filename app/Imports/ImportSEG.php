<?php

namespace App\Imports;

use App\Objects\Api;
use App\Objects\BarcodeFixer;
use App\Objects\Database;
use App\Objects\FtpManager;
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
    private $companyId = '96bec4fe-098f-0e87-2563-11a36e6447ae';
    private $path;

    /** @var ImportManager */
    private $import;

    /** @var Api */
    private $proxy;

    /** @var FtpManager */
    private $ftpManager;

    /** @var Database */
    private $db;

    public function __construct(Api $api, Database $database)
    {
        $this->proxy = $api;
        $this->db = $database;
        $this->path = storage_path('imports/seg/');

        if (!file_exists($this->path)) {
            mkdir($this->path);
        }

        $this->ftpManager = new FtpManager('seg/imports');
        $this->import = new ImportManager($database, $this->companyId);
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

                    $this->import->recordRow();

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

                        $response = $this->proxy->implementationScan(
                            $product,
                            $storeId,
                            "UNKN",
                            "",
                            $departmentId
                        );

                        $this->import->recordAdd($response);
                    } else {
                        $this->import->currentFile->skipped++;
                    }
                }

                fclose($handle);
            }

            $this->import->completeFile();
        }

        $this->import->completeImport();
    }
}

