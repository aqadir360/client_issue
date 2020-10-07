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

    private function importActiveFile($file)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                $action = strtolower(trim($data[0]));
                if ($action === 'action') {
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
        $departmentId = $this->import->getDepartmentId(trim(strtolower($data[5])));
        if ($departmentId === false) {
            return;
        }

        $product = $this->import->fetchProduct($barcode, $storeId);
        if ($product->hasInventory()) {
            $this->import->recordSkipped();
            return;
        }

        if ($product->isExistingProduct === false) {
            $product->setDescription($data[6]);
            $product->setSize($data[7]);
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

                $barcode = $this->fixBarcode(trim($data[2]));
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
                    $product->productId,
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
        while (strlen($upc) > 0 && $upc[0] === '0') {
            $upc = substr($upc, 1);
        }

        $output = str_pad(ltrim($upc, '0'), 12, '0', STR_PAD_LEFT);

        return $output . BarcodeFixer::calculateMod10Checksum($output);
    }
}
