<?php

namespace App\Imports\Refresh;

use App\Imports\ImportInterface;
use App\Models\Location;
use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;

// Refreshes Vallarta Existing Inventory
// Requires store pilot files
class VallartaInventory implements ImportInterface
{
    /** @var ImportManager */
    private $import;

    public function __construct(ImportManager $importManager)
    {
        $this->import = $importManager;
    }

    public function importUpdates()
    {
        $files = glob(storage_path('imports/Pilot_*.csv'));

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

                $deptId = $this->import->getDepartmentId(trim(strtolower($data[4])), trim(strtolower($data[10])));
                if ($deptId === false) {
                    continue;
                }

                $location = new Location(trim($data[1]), trim($data[3]));

                if ($this->shouldSkip(strtolower($location->aisle), strtolower($location->section))) {
                    $this->import->recordSkipped();
                    continue;
                }

                $product = $this->import->fetchProduct($barcode, $storeId);
                if ($product->isExistingProduct && $product->hasInventory()) {
                    if ($product->hasInventory()) {
                        $item = $product->getMatchingInventoryItem($location, $deptId);

                        if ($item !== null) {
                            if ($this->shouldDisco($location->aisle)) {
                                $this->import->discontinueInventory($item->inventory_item_id);
                            } else {
                                $this->moveInventory($item, $storeId, $deptId, $location);
                            }

                            continue;
                        }
                    }
                } else {
                    $product->setDescription($data[5]);
                    $product->setSize($data[6]);
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

    // Discontinue any items that move to OUT or to no location
    private function shouldDisco(string $aisle): bool
    {
        return empty($aisle) || strtolower($aisle) === 'out';
    }

    private function shouldSkip($aisle, $section): bool
    {
        if ($aisle == 'zzz' || $aisle == 'xxx' || $aisle == '*80' || $aisle == 'out') {
            return true;
        }

        if (($aisle == '000' || empty($aisle)) && ($section == '000' || empty($section))) {
            return true;
        }

        return false;
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
