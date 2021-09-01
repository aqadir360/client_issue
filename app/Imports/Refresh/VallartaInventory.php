<?php

namespace App\Imports\Refresh;

use App\Imports\ImportInterface;
use App\Imports\Settings\VallartaSettings;
use App\Models\Location;
use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;

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

                $product = $this->import->fetchProduct($barcode, $storeId);
                if ($product->isExistingProduct === false) {
                    $product->setDescription($data[5]);
                    $product->setSize($data[6]);
                    $productId = $this->import->createProduct($product);
                    $product->setProductId($productId);
                } else if ($product->hasInventory()) {
                    $item = $product->getMatchingInventoryItem($location, $deptId);

                    if ($item !== null) {
                        if ($this->settings->shouldDisco($location)) {
                            $this->import->discontinueInventory($item->inventory_item_id);
                            $this->import->writeFileOutput($data, 'Success: Disco');
                            continue;
                        }

                        if ($this->settings->shouldSkipLocation($location)) {
                            $this->import->recordSkipped();
                            $this->import->writeFileOutput($data, 'Skipped: Invalid Location');
                            continue;
                        }

                        $this->moveInventory($data, $item, $storeId, $deptId, $location);
                        continue;
                    }
                }

                if ($this->settings->shouldSkipLocation($location)) {
                    $this->import->recordSkipped();
                    $this->import->writeFileOutput($data, 'Skipped: Invalid Location');
                    continue;
                }

                // Adding as new any moves that do not exist in inventory
                $success = $this->import->implementationScan(
                    $product,
                    $storeId,
                    $location->aisle,
                    $location->section,
                    $deptId
                );

                if ($success !== null) {
                    $this->import->writeFileOutput($data, 'Success: Created Inventory');
                } else {
                    $this->import->writeFileOutput($data, 'Error: Could Not Create Inventory');
                }
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }

    private function moveInventory($data, $item, string $storeId, string $deptId, Location $location)
    {
        if ($item->aisle === $location->aisle) {
            if ($item->section === $location->section) {
                $this->import->recordStatic();
                $this->import->writeFileOutput($data, 'Static: Existing Location');
                return;
            }

            if (empty($location->section) && !empty($item->section)) {
                // Do not clear existing section information
                $this->import->recordStatic();
                $this->import->writeFileOutput($data, 'Static: Clearing Existing Section');
                return;
            }
        }

        $this->import->updateInventoryLocation(
            $item->inventory_item_id,
            $storeId,
            $deptId,
            $location->aisle,
            $location->section
        );

        $this->import->writeFileOutput($data, 'Success: Updated Location');
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
