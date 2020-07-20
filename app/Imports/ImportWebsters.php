<?php

namespace App\Imports;

use App\Objects\Api;
use App\Objects\BarcodeFixer;
use App\Objects\ImportFtpManager;
use App\Objects\ImportStatusOutput;

// Importing products into inventory with unknown locations
class ImportWebsters implements ImportInterface
{
    private $companyId = '2719728a-a16e-ccdc-26f5-b0e9f1f23b6e';
    private $storeId = 'e3fc1cf1-3355-1a03-0684-88bec1538bf2';
    private $deptId = 'e6ef327d-2d54-12c3-c733-b6d8352ce900'; // Grocery

    private $storagePath;

    /** @var ImportStatusOutput */
    private $importStatus;

    /** @var Api */
    private $proxy;

    /** @var ImportFtpManager */
    private $ftpManager;

    public function __construct(Api $api)
    {
        $this->proxy = $api;
        $this->storagePath = storage_path('imports/websters/');

        $this->ftpManager = new ImportFtpManager('imports/websters/', 'websters/imports');
        $this->importStatus = new ImportStatusOutput($this->companyId, 'Websters');
        $this->importStatus->setStores($this->proxy);
    }

    public function importUpdates()
    {
        $newFiles = [];
        $files = $this->ftpManager->getRecentlyModifiedFiles();
        foreach ($files as $file) {
            if (strpos($file, 'NEW') !== false) {
                $newFiles[] = $this->ftpManager->downloadFile($file);
            }
        }

        if (count($newFiles) > 0) {
            foreach ($newFiles as $file) {
                $this->importNewFile($file);
            }

            $this->ftpManager->writeLastDate();
            $this->proxy->triggerUpdateCounts($this->companyId);
            $this->importStatus->outputResults();
        }
    }

    private function importNewFile($file)
    {
        $this->importStatus->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                $this->importStatus->recordRow();

                $upc = substr(trim($data[0], "'"), 1);
                $barcode = $upc . BarcodeFixer::calculateMod10Checksum($upc);
                $product = $this->importStatus->fetchProduct($this->proxy, $barcode, $this->storeId);

                if ($product) {
                    $this->implementationScan($this->storeId, $this->deptId, $barcode);
                } elseif ($product !== null) {
                    $this->implementationScan($this->storeId, $this->deptId, $barcode, trim($data[1]), '');
                }
            }

            fclose($handle);
        }

        $this->importStatus->completeFile();
    }

    private function implementationScan($storeId, $departmentId, $barcode, $description = null, $size = null)
    {
        $response = $this->proxy->implementationScan(
            $barcode,
            $storeId,
            'UNKN',
            '',
            $departmentId,
            $description,
            $size
        );

        $this->importStatus->recordResult($response);
    }
}
