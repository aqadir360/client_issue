<?php

namespace App\Imports;

use App\Objects\Api;
use App\Objects\BarcodeFixer;
use App\Objects\Database;
use App\Objects\FtpManager;
use App\Objects\ImportManager;
use App\Objects\InventoryCompare;

class ImportHansens implements ImportInterface
{
    private $companyId = '61ef52da-c0e1-11e7-a59b-080027c30a85';
    private $path;
    private $unzippedPath;

    /** @var Api */
    private $proxy;

    /** @var FtpManager */
    private $ftpManager;

    /** @var ImportManager */
    private $import;

    public function __construct(Api $api, Database $database)
    {
        $this->proxy = $api;
        $this->path = storage_path('imports/hansens/');
        $this->ftpManager = new FtpManager('hansens/imports/weekly');
        $this->import = new ImportManager($database, $this->companyId);
        $this->import->setSkipList();
        $this->unzippedPath = storage_path('imports/hansens_unzipped/');
    }

    public function importUpdates()
    {
//        $files = $this->ftpManager->getRecentlyModifiedFiles();
//        foreach ($files as $file) {
//            if (strpos($file, 'zip') === false) {
//                continue;
//            }
//            $zipFile = $this->ftpManager->downloadFile($file);
//            $this->ftpManager->unzipFile($zipFile, 'hansens_unzipped');
//        }

        $filesToImport = glob($this->unzippedPath . '*');

        foreach ($filesToImport as $file) {
            $this->importStoreInventory($file);
        }

        $this->completeImport();
    }

    public function completeImport(string $error = '')
    {
        $this->proxy->triggerUpdateCounts($this->companyId);
        $this->ftpManager->writeLastDate();
        $this->import->completeImport($error);
    }

    private function importStoreInventory($file)
    {
        $this->import->startNewFile($file);

        $storeNum = substr(basename($file), 0, 4);
        $storeId = $this->import->storeNumToStoreId($storeNum);
        if ($storeId === false) {
            return $this->completeFile($file, "Invalid Store $storeNum");
        }

        $compare = new InventoryCompare($this->companyId, $storeId, $this->proxy, $this->import);

        $exists = $this->setFileInventory($compare, $file);
        if (!$exists) {
            return $this->completeFile($file, "Skipping $storeNum - Import file was empty");
        }

        $compare->setExistingInventory();
        $compare->compareInventorySets();

        return $this->completeFile($file);
    }

    private function completeFile($file, $message = '')
    {
        if ($message !== '') {
            $this->import->outputContent($message);
        }

        $this->import->completeFile();
//        unlink($file);
        return true;
    }

    private function setFileInventory(InventoryCompare $compare, $file): bool
    {
        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                if ($data[0] === "store_id") {
                    continue;
                }

                $upc = '0' . BarcodeFixer::fixUpc(trim($data[4]));
                $loc = $this->parseLocation(trim($data[13]));

                $compare->setFileInventoryItem(
                    $upc,
                    $loc['aisle'],
                    $loc['section'],
                    $loc['shelf'],
                    trim($data[6]),
                    trim($data[7])
                );
            }

            fclose($handle);
        }

        return $compare->hasFileInventory();
    }

    private function parseLocation(string $location)
    {
        return [
            'aisle' => substr($location, 0, 2),
            'section' => substr($location, 2, 3),
            'shelf' => substr($location, 5, 2),
        ];
    }
}
