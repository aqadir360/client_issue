<?php

namespace App\Imports\Refresh;

use App\Imports\ImportInterface;
use App\Imports\Settings\VallartaSettings;
use App\Models\Location;
use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;
use App\Objects\InventoryCompare;

// Refreshes Vallarta Existing Inventory
// Requires store pilot files
class VallartaInventory implements ImportInterface
{
    /** @var ImportManager */
    private $import;

    /** @var VallartaSettings */
    private $settings;

    public function __construct(ImportManager $importManager)
    {
        $this->import = $importManager;
        $this->import->setSkipList();
        $this->settings = new VallartaSettings();
    }

    public function importUpdates()
    {
        $files = $this->import->downloadFilesByName('Pilot_');

        foreach ($files as $filePath) {
            $this->importActiveFile($filePath);
        }

        $this->import->completeImport();
    }

    private function importActiveFile($file)
    {
        $this->import->startNewFile($file);

        $storeNum = substr(basename($file), 6, -4);
        $storeId = $this->import->storeNumToStoreId(intval($storeNum));
        if ($storeId === false) {
            echo "Invalid store " . $storeNum . PHP_EOL;
            return;
        }

        $compare = new InventoryCompare($this->import, $storeId, 0);
        $this->setFileInventory($compare, $file);

        $this->import->outputAndResetFile();

        $compare->setExistingInventory();
        $compare->compareInventorySets();

        $this->import->completeFile();
    }

    private function setFileInventory(InventoryCompare $compare, string $file)
    {
        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 5000, "|")) !== false) {
                if (!$this->import->recordRow()) {
                    break;
                }

                $barcode = $this->fixBarcode(trim($data[0]));
                if ($this->import->isInvalidBarcode($barcode, $data[0])) {
                    $this->import->writeFileOutput($data, 'Skipped: Invalid Barcode');
                    continue;
                }

                if ($this->import->isInSkipList($barcode)) {
                    $this->import->writeFileOutput($data, 'Skipped: In Skip List');
                    continue;
                }

                $deptId = $this->import->getDepartmentId(trim(strtolower($data[4])), trim(strtolower($data[11])));
                if ($deptId === false) {
                    $this->import->writeFileOutput($data, 'Skipped: Invalid Department');
                    continue;
                }

                $location = new Location(trim($data[1]), trim($data[3]));
                if ($this->settings->shouldSkipLocation($location)) {
                    $this->import->recordSkipped();
                    $this->import->writeFileOutput($data, 'Skipped: Invalid Location');
                    continue;
                }

                $product = $this->import->fetchProduct($barcode);
                if ($product->isExistingProduct === false) {
                    $product->setDescription($data[5]);
                    $product->setSize($data[6]);
                    $productId = $this->import->createProduct($product);
                    $product->setProductId($productId);
                }

                $compare->setFileInventoryItem(
                    $product->barcode,
                    $location,
                    trim($data[5]),
                    trim($data[6]),
                    $deptId
                );
            }

            fclose($handle);
        }
    }

    private function fixBarcode(string $upc)
    {
        while (strlen($upc) > 0 && $upc[0] === '0') {
            $upc = substr($upc, 1);
        }

        $output = str_pad(ltrim($upc, '0'), 12, '0', STR_PAD_LEFT);

        return $output . BarcodeFixer::calculateMod10Checksum($output);
    }
}
