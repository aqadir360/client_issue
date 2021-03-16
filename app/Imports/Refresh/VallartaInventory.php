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
        }

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                if (!$this->import->recordRow()) {
                    break;
                }

                $barcode = $this->fixBarcode(trim($data[0]));
                if ($this->import->isInvalidBarcode($barcode, $data[0])) {
                    continue;
                }

                if ($this->import->isInSkipList($barcode)) {
                    continue;
                }

                $deptId = $this->import->getDepartmentId(trim(strtolower($data[4])), trim(strtolower($data[11])));
                if ($deptId === false) {
                    continue;
                }

                $location = new Location(trim($data[1]), trim($data[3]));

                $product = $this->import->fetchProduct($barcode, $storeId);
                if ($product->isExistingProduct && $product->hasInventory()) {
                    $item = $product->getMatchingInventoryItem($location, $deptId);

                    if ($item !== null) {
                        if ($this->settings->shouldDisco($location)) {
                            $this->import->discontinueInventory($item->inventory_item_id);
                            continue;
                        } elseif ($this->settings->shouldSkipLocation($location)) {
                            $this->import->recordSkipped();
                            continue;
                        } else {
                            $this->moveInventory($item, $storeId, $deptId, $location);
                        }
                    }
                } else {
                    $product->setDescription($data[5]);
                    $product->setSize($data[6]);
                }

                if ($this->settings->shouldSkipLocation($location)) {
                    $this->import->recordSkipped();
                    continue;
                }

                // Adding as new any moves that do not exist in inventory
                $this->import->implementationScan(
                    $product,
                    $storeId,
                    $location->aisle,
                    $location->section,
                    $deptId
                );
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }

    private function moveInventory($item, string $storeId, string $deptId, Location $location)
    {
        if ($item->aisle == $location->aisle) {
            if ($item->section == $location->section) {
                $this->import->recordStatic();
                return;
            }

            if (empty($location->section) && !empty($item->section)) {
                // Do not clear existing section information
                $this->import->recordStatic();
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
    }

    private function fixBarcode(string $upc)
    {
//        try {
            while (strlen($upc) > 0 && $upc[0] === '0') {
                $upc = substr($upc, 1);
            }

            $output = str_pad(ltrim($upc, '0'), 12, '0', STR_PAD_LEFT);

            return $output . BarcodeFixer::calculateMod10Checksum($output);
//        } catch (\Exception $e) {
//            return '0';
//        }
    }
}
