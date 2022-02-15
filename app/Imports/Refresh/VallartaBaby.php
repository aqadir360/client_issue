<?php

namespace App\Imports\Refresh;

use App\Imports\ImportInterface;
use App\Imports\Settings\VallartaSettings;
use App\Models\Location;
use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;

// Refreshes Vallarta Baby Inventory
// Requires store pilot files
class VallartaBaby implements ImportInterface
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
        $files = $this->import->downloadFilesByName('Pilot_Baby_');

        foreach ($files as $filePath) {
            $this->importActiveFile($filePath);
        }

        $this->import->completeImport();
    }

    private function importActiveFile($file)
    {
        $this->import->startNewFile($file);

        $storeNum = substr(basename($file), 11, -4);
        $storeId = $this->import->storeNumToStoreId(intval($storeNum));
        if ($storeId === false) {
            echo "Invalid store " . $storeNum . PHP_EOL;
            return;
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

                $foundInBabyDept = false;
                $deptId = '7683606a-fb43-bfec-0bcf-704878ca4871'; // Baby Food / Formula
                $product = $this->import->fetchProduct($barcode, $storeId);

                if (!$product->isExistingProduct) {
                    $product->setDescription($data[5]);
                    $product->setSize($data[6]);
                } elseif ($product->hasInventory()) {
                    // Check if one already exists in the department
                    foreach ($product->inventory as $item) {
                        if ($item->department_id === $deptId) {
                            $foundInBabyDept = true;
                        }
                    }

                    if ($foundInBabyDept) {
                        $this->import->recordStatic();
                        $this->import->writeFileOutput($data, "Static: Existing Item");

                        // If one exists and other items exist, disco the others
                        if (count($product->inventory) > 1) {
                            foreach ($product->inventory as $item) {
                                if ($item->department_id !== $deptId) {
                                    var_dump($item);
//                                    $this->import->writeFileOutput($data, "Disco: Wrong Dept Duplicate");
                                    $this->import->discontinueInventory($item->inventory_item_id);
                                }
                            }
                        }

                        continue;
                    } else {
                        // If none exist in the department, move to baby dept
                        $item = $product->inventory[0];
                        $this->import->updateInventoryLocation(
                            $item->inventory_item_id,
                            $storeId,
                            $deptId,
                            $item->aisle,
                            $item->section,
                            $item->shelf
                        );
                        $this->import->writeFileOutput($data, "Move: Updated Dept");

                        if (count($product->inventory) > 1) {
                            var_dump($product->inventory);
                        }
                    }
                }

                $location = new Location(trim($data[1]), trim($data[3]));

                if ($this->settings->shouldSkipLocation($location)) {
                    $this->import->writeFileOutput($data, "Skip: Invalid Location");
                    $this->import->recordSkipped();
                    continue;
                }

                $this->import->implementationScan(
                    $product,
                    $storeId,
                    $location->aisle,
                    $location->section,
                    $deptId
                );

                $this->import->writeFileOutput($data, "Success: Created Item");
            }

            fclose($handle);
        }

        $this->import->completeFile();
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
