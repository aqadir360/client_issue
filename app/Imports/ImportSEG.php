<?php

namespace App\Imports;

use App\Objects\Api;
use App\Objects\BarcodeFixer;
use App\Objects\ImportFtpManager;
use App\Objects\ImportStatusOutput;

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

    private $departments;

    /** @var ImportStatusOutput */
    private $importStatus;

    /** @var Api */
    private $proxy;

    /** @var ImportFtpManager */
    private $ftpManager;

    public function __construct(Api $api)
    {
        $this->proxy = $api;
        $this->path = storage_path('imports/seg/');

        if (!file_exists($this->path)) {
            mkdir($this->path);
        }

        $this->ftpManager = new ImportFtpManager('imports/seg/', 'seg/imports');
        $this->importStatus = new ImportStatusOutput($this->companyId, "SEG");
        $this->importStatus->setStores($this->proxy);
    }

    public function importUpdates()
    {
        $this->setDepartments();

        $files = glob($this->path . '*.csv');
        foreach ($files as $file) {
            $this->importStatus->startNewFile($file);

            if (($handle = fopen($file, "r")) !== false) {
                while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                    if ($data[0] == 'UPC') {
                        continue;
                    }

                    $this->importStatus->recordRow();

                    $storeId = $this->importStatus->storeNumToStoreId($data[3]);
                    if ($storeId === false) {
                        continue;
                    }

                    $departmentId = $this->deptNameToDeptId($data[1]);
                    if ($departmentId === false) {
                        continue;
                    }

                    $upc = $this->fixBarcode($data[0]);
                    $product = $this->importStatus->fetchProduct($this->proxy, $upc, $storeId);

                    if (null === $product) {
                        continue;
                    }

                    if (false === $product || count($product['inventory']) === 0) {
                        $this->createNewInventory(
                            $upc,
                            $storeId,
                            $departmentId,
                            ucwords(strtolower(trim($data[4]))),
                            strtolower($data[5])
                        );
                    } else {
                        $this->importStatus->currentFile->skipped++;
                    }
                }

                fclose($handle);
            }

            $this->importStatus->completeFile();
        }

        $this->importStatus->outputResults();
    }

    private function createNewInventory(
        string $barcode,
        string $storeId,
        string $departmentId,
        string $description,
        string $size
    ) {
        $response = $this->proxy->implementationScan(
            $barcode,
            $storeId,
            "UNKN",
            "",
            $departmentId,
            $description,
            $size
        );

        $this->importStatus->recordResult($response);
    }

    private function setDepartments()
    {
        $response = $this->proxy->fetchDepartments($this->companyId);

        foreach ($response['departments'] as $department) {
            $this->departments[$this->normalizeName($department['displayName'])] = $department['departmentId'];
        }
    }

    private function deptNameToDeptId($input)
    {
        $deptName = $this->normalizeName($input);

        if (isset($this->departments[$deptName])) {
            return $this->departments[$deptName];
        }

        $this->importStatus->addInvalidDepartment($input);
        return false;
    }

    private function normalizeName($input)
    {
        $output = strtolower(preg_replace('![^a-z0-9]+!i', '', $input));

        switch ($output) {
            case 'yogurt':
                return 'dairyyogurt';
            default:
                return $output;
        }
    }

    private function fixBarcode($barcode)
    {
        return BarcodeFixer::fixUpc(trim($barcode));
    }
}

