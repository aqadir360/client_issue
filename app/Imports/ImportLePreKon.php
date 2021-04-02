<?php

namespace App\Imports;

use App\Models\Location;
use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;

// Le-Pre-Kon Inventory and Metrics Import
// Expects new, disco, and metrics files weekly per store
class ImportLePreKon implements ImportInterface
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
        $activeList = $this->import->downloadFilesByName('new');
        $updateList = $this->import->downloadFilesByName('delt');
        $metricsList = $this->import->downloadFilesByName('work');

        foreach ($activeList as $filePath) {
            $this->importActiveFile($filePath);
        }

        foreach ($updateList as $filePath) {
            $this->importDiscoFile($filePath);
        }

        foreach ($metricsList as $filePath) {
            $this->importMetricsFile($filePath);
        }

        $this->import->completeImport();
    }

    private function importActiveFile($file)
    {
        $this->import->startNewFile($file);

        $storeNum = substr(basename($file), 0, 4);

        $storeId = $this->import->storeNumToStoreId($storeNum);
        if ($storeId === false) {
            $this->import->recordFileLineError('Error', 'Invalid Store Id');
            $this->import->completeFile();
            return;
        }

        if (($handle = fopen($file, "r")) !== false) {

            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                if (!$this->import->recordRow()) {
                    break;
                }

                $departmentId = $this->import->getDepartmentId(trim($data[5]));
                if (!$departmentId) {
                    $departmentId = $this->import->getDepartmentId('grocery');
                }

                if (trim($data[0]) !== 'ADD') {
                    $this->import->recordSkipped();
                    continue;
                }

                $upc = BarcodeFixer::fixUpc(trim($data[1]));
                if ($this->import->isInvalidBarcode($upc, $data[1])) {
                    continue;
                }

                $product = $this->import->fetchProduct($upc, $storeId);
                $location = new Location('UNKN', '');

                if ($product->isExistingProduct) {
                    $productId = $product->productId;
                    if ($product->hasInventory()) {
                        $this->import->implementationScan(
                            $product,
                            $storeId,
                            $location->aisle,
                            $location->section,
                            $departmentId
                        );
                    } else {
                        $this->import->recordStatic();
                    }
                } else {
                    $product->setDescription($data[2]);
                    $product->setSize($this->parseSize(trim($data[3]), trim($data[4])));

                    $productId = $this->import->implementationScan(
                        $product,
                        $storeId,
                        $location->aisle,
                        $location->section,
                        $departmentId
                    );
                }

                if ($productId) {
                    $this->import->persistMetric($storeId, $product, 0, $this->parseRetail($data), 0);
                }
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }

    private function importDiscoFile($file)
    {
        $this->import->startNewFile($file);

        $storeNum = substr(basename($file), 0, 4);

        $storeId = $this->import->storeNumToStoreId($storeNum);
        if ($storeId === false) {
            $this->import->recordFileLineError('Error', 'Invalid Store Id');
            $this->import->completeFile();
            return;
        }

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                if (!$this->import->recordRow()) {
                    break;
                }

                if ($data[0] !== "PROD_DEL") {
                    $this->import->recordSkipped();
                    continue;
                }

                $upc = BarcodeFixer::fixUpc(intval($data[4]));
                if ($this->import->isInvalidBarcode($upc, $data[4])) {
                    continue;
                }

                $this->import->discontinueProductByBarcode($storeId, $upc);
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }

    private function importMetricsFile($file)
    {
        $this->import->startNewFile($file);

        $storeNum = substr(basename($file), 0, 4);

        $storeId = $this->import->storeNumToStoreId($storeNum);
        if ($storeId === false) {
            $this->import->recordFileLineError('Error', 'Invalid Store Id');
            $this->import->completeFile();
            return;
        }

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 10000, "|")) !== false) {
                if (!$this->import->recordRow()) {
                    break;
                }

                $upc = BarcodeFixer::fixUpc(intval($data[65]));
                if ($this->import->isInvalidBarcode($upc, $data[65])) {
                    continue;
                }

                $product = $this->import->fetchProduct($upc);
                if ($product->isExistingProduct === false) {
                    $this->import->recordSkipped();
                    continue;
                }

                $cost = floatval($data[67]) / floatval($data[83]);
                $retail = floatval($data[73] / floatval($data[71]));
                // sending 90 day movement
                $movement = round(floatval($data[77]) / 90, 4);

                if ($cost > $retail) {
                    $this->import->recordFileLineError("Invalid", "$upc cost $cost is more than retail $retail");
                    $cost = 0;
                }

                $this->import->persistMetric(
                    $storeId,
                    $product,
                    $this->import->convertFloatToInt($cost),
                    $this->import->convertFloatToInt($retail),
                    $this->import->convertFloatToInt($movement)
                );
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }

    private function parseSize($value, $measure)
    {
        switch ($measure) {
            case 'FL O':
                return $value . 'fz';
            case 'EA':
            case 'COUN':
                return intval($value) . 'ct';
            case 'OUNC':
                return $value . 'oz';
            case 'LB':
            case 'POUN':
                return $value . 'lb';
            case '?':
                return $value;
            default:
                echo "$measure Measure not mapped" . PHP_EOL;
                return $value . strtolower($measure);
        }
    }

    private function parseRetail($data): int
    {
        if ($data[6] === '1.00') {
            return $this->import->convertFloatToInt(floatval($data[7]));
        }

        if (floatval($data[6]) == 0) {
            return 0;
        }

        return $this->import->convertFloatToInt(floatval($data[7]) / floatval($data[6]));
    }
}
