<?php

namespace App\Imports;

use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;
use App\Objects\InventoryCompare;

class ImportHansens implements ImportInterface
{
    /** @var ImportManager */
    private $import;

    public function __construct(ImportManager $importManager)
    {
        $this->import = $importManager;
        $this->import->setSkipList();
    }

    public function getFilesToImport()
    {
        $files = $this->import->ftpManager->getRecentlyModifiedFiles();
        foreach ($files as $file) {
            if (strpos($file, 'zip') === false) {
                continue;
            }
            $zipFile = $this->import->ftpManager->downloadFile($file);
            $this->import->ftpManager->unzipFile($zipFile, 'hansens_unzipped');
        }

        return glob(storage_path('imports/hansens_unzipped/*'));
    }

    public function importUpdates()
    {
        $filesToImport = $this->getFilesToImport();

        foreach ($filesToImport as $file) {
            $this->import->startNewFile($file);
            $this->importStoreInventory($file);
            $this->import->completeFile();
        }

        $this->import->completeImport();
    }

    private function importStoreInventory($file)
    {
        $storeNum = substr(basename($file), 0, 4);
        $storeId = $this->import->storeNumToStoreId($storeNum);
        if ($storeId === false) {
            $this->import->outputContent("Invalid Store $storeNum");
            return;
        }

        $compare = new InventoryCompare($this->import, $storeId);

        $exists = $this->setFileInventory($compare, $file);
        if (!$exists) {
            $this->import->outputContent("Skipping $storeNum - Import file was empty");
            return;
        }

        $compare->setExistingInventory();
        $compare->compareInventorySets();
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

        return $compare->fileInventoryCount() > 0;
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
