<?php

namespace App\Imports;

use App\Models\Location;
use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;
use App\Objects\InventoryCompareByLocation;

class ImportHardings implements ImportInterface
{
    /** @var ImportManager */
    private $import;

    public function __construct(ImportManager $importManager)
    {
        $this->import = $importManager;
        $this->import->setSkipList();
    }

    public function importUpdates()
    {
        $filesToImport = $this->getFilesToImport();

        foreach ($filesToImport as $file) {
            $this->import->startNewFile($file);

            $storeNum = substr(basename($file), 3, 3);
            $this->importStoreInventory($file, $storeNum);

            $this->import->completeFile();
        }

        $this->import->completeImport();
    }

    private function getFilesToImport()
    {
        $files = $this->import->ftpManager->getRecentlyModifiedFiles();
        $filesToDownload = [];

        // Import only the most recent file per store
        foreach ($files as $file) {
            if (strpos($file, '7z') === false) {
                continue;
            }

            $storeNum = substr(basename($file), 3, 3);
            $filesToDownload[intval($storeNum)] = $file;
        }

        foreach ($filesToDownload as $file) {
            $zipFile = $this->import->ftpManager->downloadFile($file);
            $this->import->ftpManager->unzipSevenZipFile($zipFile, 'hardings_unzipped');
        }

        return glob(storage_path('imports/hardings_unzipped/*'));
    }

    private function importStoreInventory($file, $storeNum)
    {
        $storeId = $this->import->storeNumToStoreId($storeNum);
        if ($storeId === false) {
            $this->import->outputContent("Invalid Store $storeNum");
            return;
        }

        $compare = new InventoryCompareByLocation($this->import, $storeId);

        $exists = $this->setFileInventory($compare, $file, $storeId);
        if (!$exists) {
            $this->import->outputContent("Skipping $storeId - Import file was empty");
            return;
        }

        $compare->setExistingInventory();

        // DCP2-560: Skipping location updates for North Muskegon and Middleville due to bad location data
        switch ($storeNum) {
            case 165: // North Muskegon
            case 455: // Middleville
                $compare->compareInventorySetsWithoutMoves();
                break;
            default:
                $compare->compareInventorySets();
        }
    }

    private function setFileInventory(InventoryCompareByLocation $compare, $file, $storeId): bool
    {
        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 5000, "|")) !== false) {
                if (count($data) < 37) {
                    $this->import->recordFileLineError('ERROR', 'Line too short to parse');
                    continue;
                }

                if (trim($data[0]) === 'STORE') {
                    continue;
                }

                $upc = BarcodeFixer::fixUpc(trim($data[3]));
                if ($this->import->isInvalidBarcode($upc, $data[3])) {
                    $this->import->writeFileOutput([$data[3]], "Skip: Invalid Barcode");
                    continue;
                }

                $loc = $this->parseLocation(trim($data[18]));
                if ($loc->valid === false && !empty(trim($data[18]))) {
                    $this->import->writeFileOutput([$upc, $data[18]], "Skip: Invalid Location");
                    continue;
                }

                $departmentId = $this->import->getDepartmentId(intval($data[4]), intval($data[28]));
                if ($departmentId === false) {
                    $this->import->writeFileOutput([$upc, trim($data[4]), trim($data[28])], "Skip: Invalid Department");
                    continue;
                }

                $product = $this->import->fetchProduct($upc);
                if ($product->isExistingProduct === false) {
                    $product->setDescription(trim($data[35]));
                    $product->setSize(trim(trim($data[36]) . " " . trim($data[37])));
                    $productId = $this->import->createProduct($product);

                    if ($productId) {
                        $product->setProductId($productId);
                    } else {
                        $this->import->writeFileOutput([$upc, $data[35]], "Skip: Invalid Product");
                        continue;
                    }
                }

                $compare->setFileInventoryItem(
                    $upc,
                    $loc,
                    $product->description,
                    $product->size,
                    $departmentId
                );

                if (count($data) < 61) {
                    continue;
                }

                $cost = $this->parseCost(floatval($data[60]), intval($data[61]));
                $retail = $this->parseRetail(trim($data[7]));

                $this->import->persistMetric(
                    $storeId,
                    $product,
                    $this->import->convertFloatToInt($cost),
                    $this->import->convertFloatToInt($retail),
                    $this->import->convertFloatToInt(abs(floatval($data[33]))),
                    false
                );
            }

            fclose($handle);
        }

        $total = $compare->fileInventoryCount();
        $this->import->setTotalCount($total);
        return $total > 0;
    }

    private function parseLocation(string $input): Location
    {
        $location = new Location();
        if (empty(trim($input)) || strpos($input, '_') === false) {
            return $location;
        }

        $location->aisle = substr($input, 0, 2);
        $location->section = str_replace('_', '', substr($input, 3, 5));
        $location->shelf = substr($input, 9, 2);
        $location->valid = true;

        return $location;
    }

    private function parseCost(float $caseCost, int $caseCount): float
    {
        return ($caseCount === 0) ? 0 : $caseCost / $caseCount;
    }

    private function parseRetail(string $input): float
    {
        if (strlen($input) > 2) {
            $input = substr($input, 2);
        }

        return intval($input) / 100;
    }
}
