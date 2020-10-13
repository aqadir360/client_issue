<?php

namespace App\Imports;

use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;

// Webster's Inventory Import
// Expects New Items file weekly
// Adds all products with unknown location and grocery department
class ImportWebsters implements ImportInterface
{
    /** @var ImportManager */
    private $import;

    private $storeId;

    public function __construct(ImportManager $importManager)
    {
        $this->import = $importManager;
        // TODO: remove hard coding and switch to store number
        $this->storeId = "e3fc1cf1-3355-1a03-0684-88bec1538bf2"; // Webster's Marketplace
    }

    public function importUpdates()
    {
        $newFiles = $this->import->downloadFilesByName('NEW');

        foreach ($newFiles as $file) {
            $this->importNewFile($file);
        }

        $this->import->completeImport();
    }

    private function importNewFile($file)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                if (!$this->import->recordRow()) {
                    break;
                }

                $upc = substr(trim($data[0], "'"), 1);
                $barcode = $upc . BarcodeFixer::calculateMod10Checksum($upc);
                $product = $this->import->fetchProduct($barcode, $this->storeId);

                if (!$product->isExistingProduct) {
                    $product->setDescription($data[1]);
                }

                $this->import->implementationScan(
                    $product,
                    $this->storeId,
                    'UNKN',
                    '',
                    $this->import->getDepartmentId('grocery')
                );
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }
}
