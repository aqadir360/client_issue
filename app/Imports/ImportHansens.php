<?php

namespace App\Imports;

use App\Models\Location;
use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;
use App\Objects\InventoryCompare;
use App\Objects\SkippedLocations;
use Illuminate\Support\Facades\Storage;

class ImportHansens implements ImportInterface
{
    /** @var ImportManager */
    private $import;

    public function __construct(ImportManager $importManager)
    {
        $this->import = $importManager;
        $this->import->setSkipList();
        $this->import->setSkippedLocations(
            new SkippedLocations(['', '99', '999'], [''], ['99', '98'])
        );
    }

    public function getFilesToImport()
    {
        // Gets files from Hansen's server
        $hansensFtp = Storage::disk('hansensFtp');
        $files = $hansensFtp->allFiles();
        foreach ($files as $file) {
            Storage::disk('imports')->put(basename($file), $hansensFtp->get($file));
            $zipFile = storage_path('imports/' . basename($file));
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

                $compare->setFileInventoryItem(
                    $upc,
                    $this->parseLocation(trim($data[13])),
                    trim($data[6]),
                    trim($data[7])
                );
            }

            fclose($handle);
        }

        return $compare->fileInventoryCount() > 0;
    }

    private function parseLocation(string $location): Location
    {
        $location = new Location(
            substr($location, 0, 2),
            substr($location, 2, 3),
            substr($location, 5, 2),
        );
        $location->valid = true;
        return $location;
    }
}
