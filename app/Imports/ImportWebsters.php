<?php

namespace App\Imports;

use App\Models\Product;
use App\Objects\Api;
use App\Objects\BarcodeFixer;
use App\Objects\Database;
use App\Objects\FtpManager;
use App\Objects\ImportManager;

// Webster's Inventory Import
// Expects New Items file weekly
// Adds all products with unknown location and grocery department
class ImportWebsters implements ImportInterface
{
    private $companyId = '2719728a-a16e-ccdc-26f5-b0e9f1f23b6e';
    private $storeId = 'e3fc1cf1-3355-1a03-0684-88bec1538bf2';

    /** @var ImportManager */
    private $import;

    /** @var Api */
    private $proxy;

    /** @var FtpManager */
    private $ftpManager;

    public function __construct(Api $api, Database $database)
    {
        $this->proxy = $api;
        $this->ftpManager = new FtpManager('websters/imports');
        $this->import = new ImportManager($database, $this->companyId);
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

            $this->completeImport();
        }
    }

    public function completeImport(string $error = '')
    {
        $this->proxy->triggerUpdateCounts($this->companyId);
        $this->ftpManager->writeLastDate();
        $this->import->completeImport($error);
    }

    private function importNewFile($file)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                $this->import->recordRow();

                $upc = substr(trim($data[0], "'"), 1);
                $barcode = $upc . BarcodeFixer::calculateMod10Checksum($upc);
                $product = $this->import->fetchProduct($barcode, $this->storeId);

                if ($product->isExistingProduct) {
                    $this->implementationScan($this->storeId, $product);
                } else {
                    $product->setDescription($data[1]);
                    $this->implementationScan($this->storeId, $product);
                }
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }

    private function implementationScan($storeId, Product $product)
    {
        $response = $this->proxy->implementationScan(
            $product,
            $storeId,
            'UNKN',
            '',
            $this->import->getDepartmentId('grocery')
        );

        $this->import->recordAdd($response);
    }
}
