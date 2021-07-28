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
        $newFiles = $this->import->downloadFilesByName('DCP_EXPORT_NEW');

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

                if ($this->import->isInvalidBarcode($barcode, $upc)) {
                    $this->import->writeFileOutput($data, 'Error: Invalid Barcode');
                    continue;
                }

                $product = $this->import->fetchProduct($barcode, $this->storeId);

                if ($product->hasInventory()) {
                    $this->import->recordSkipped();
                    $this->import->writeFileOutput($data, 'Skipped: Existing Inventory');
                    continue;
                }

                if (!$product->isExistingProduct) {
                    $name = trim($data[1], "'");
                    $sizePosition = $this->findSize($name);

                    if ($sizePosition > 0) {
                        $product->setDescription(trim(substr($name, 0, $sizePosition)));
                        $product->setSize(trim(substr($name, $sizePosition)));
                    } else {
                        $product->setDescription($name);
                    }
                }

                $productId = $this->import->implementationScan(
                    $product,
                    $this->storeId,
                    'UNKN',
                    '',
                    $this->import->getDepartmentId('grocery')
                );

                if ($productId === null) {
                    $this->import->writeFileOutput($data, 'Error: Unable to create product');
                } else {
                    $this->import->writeFileOutput($data, 'Success: Created Inventory');
                }
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }

    // Tries to find the last complete number in the description
    private function findSize(string $name)
    {
        $sizePosition = 0;
        $numFound = false;

        for ($i = strlen($name) - 1; $i >= 0; $i--) {
            if ($numFound && $name[$i] === ' ') {
                $sizePosition = $i;
                break;
            }

            if (is_numeric($name[$i])) {
                $numFound = true;
            }
        }

        return $sizePosition;
    }
}
