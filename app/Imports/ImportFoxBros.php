<?php

namespace App\Imports;

use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;

// Fox Brothers Inventory and Metrics Import
// Expects update and metrics files weekly
class ImportFoxBros implements ImportInterface
{
    /** @var ImportManager */
    private $import;

    public function __construct(ImportManager $importManager)
    {
        $this->import = $importManager;
    }

    public function importUpdates()
    {
        $updateList = $this->import->downloadFilesByName('update_');
        $metricsList = $this->import->downloadFilesByName('metrics_');

        foreach ($updateList as $filePath) {
            $this->importActiveFile($filePath);
        }

        foreach ($metricsList as $filePath) {
            $this->importMetricsFile($filePath);
        }

        $this->import->completeImport();
    }

    // [0] ACTION
    // [1] STORE
    // [2] BARCODE
    // [3] AISLE
    // [4] SECTION
    // [5] DEPARTMENT NAME
    // [6] PRODUCT GROUP_CATG
    // [7] PRODUCT DESCRIPTION
    // [8] PRODUCT SIZE
    private function importActiveFile($file)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                $action = strtolower(trim($data[0]));
                if ($action === 'action' || $data[1] === 'STORE') {
                    continue;
                }

                if (!$this->import->recordRow()) {
                    break;
                }

                $storeId = $this->import->storeNumToStoreId(intval($data[1]));
                if ($storeId === false) {
                    continue;
                }

                $barcode = $this->fixBarcode(trim($data[2]));
                if ($this->import->isInvalidBarcode($barcode, $data[2])) {
                    continue;
                }

                switch ($action) {
                    case 'disco':
                        $this->import->discontinueProductByBarcode($storeId, $barcode);
                        break;
                    case 'add':
                        $this->handleAdd($data, $barcode, $storeId);
                        break;
                    default:
                        $this->import->recordFileLineError('Skipped', "Unknown Action $action");
                }
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }

    private function handleAdd($data, $barcode, $storeId)
    {
        $product = $this->import->fetchProduct($barcode, $storeId);

        if ($product->isExistingProduct === false) {
            $product->setDescription($data[7]);
            $product->setSize($data[8]);
        }

        $departmentId = $this->import->getDeptIdAndRecordCategory(
            $product,
            trim(strtolower($data[5])),
            trim(strtolower($data[6]))
        );

        if ($departmentId === false) {
            return;
        }

        if ($product->hasInventory()) {
            $this->import->recordSkipped();
            return;
        }

        $this->import->implementationScan(
            $product,
            $storeId,
            trim($data[3]),
            trim($data[4]),
            $departmentId
        );
    }

    private function importMetricsFile($file)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                if (!$this->import->recordRow()) {
                    break;
                }

                $storeId = $this->import->storeNumToStoreId(intval($data[1]));
                if ($storeId === false) {
                    continue;
                }

                // Metrics barcodes include check digit
                $barcode = str_pad(ltrim(trim($data[2]), '0'), 13, '0', STR_PAD_LEFT);
                if ($this->import->isInvalidBarcode($barcode, $data[2])) {
                    continue;
                }

                $product = $this->import->fetchProduct($barcode);
                if ($product->isExistingProduct === false) {
                    $this->import->recordSkipped();
                    continue;
                }

                $this->import->persistMetric(
                    $storeId,
                    $product,
                    $this->import->convertFloatToInt(floatval($data[5])),
                    $this->import->convertFloatToInt(floatval($data[4])),
                    $this->import->convertFloatToInt(floatval($data[3])),
                    true
                );
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }

    private function fixBarcode(string $upc)
    {
        // Update barcodes do not include check digit and may be left zero padded
        $output = str_pad(ltrim($upc, '0'), 12, '0', STR_PAD_LEFT);
        return $output . BarcodeFixer::calculateMod10Checksum($output);
    }
}
