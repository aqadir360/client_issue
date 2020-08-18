<?php

namespace App\Imports;

use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;

// Buehler's Inventory Import
// Expects Disco and Active Items files weekly
class ImportBuehlers implements ImportInterface
{
    /** @var ImportManager */
    private $import;

    public function __construct(ImportManager $import)
    {
        $this->import = $import;
        $this->import->setSkipList();
    }

    public function importUpdates()
    {
        $discoFiles = $this->import->downloadFilesByName('Disc');
        $activeFiles = $this->import->downloadFilesByName('Disc', false);

        foreach ($discoFiles as $file) {
            $this->importDiscoFile($file);
        }

        foreach ($activeFiles as $file) {
            $this->importActiveFile($file);
        }

        $this->import->completeImport();
    }

    private function importDiscoFile(string $file)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                if (!$this->import->recordRow()) {
                    break;
                }

                $upc = BarcodeFixer::fixUpc(trim($data[2]));
                if ($this->import->isInvalidBarcode($upc, $data[2])) {
                    continue;
                }

                $storeId = $this->import->storeNumToStoreId($data[1]);
                if ($storeId === false) {
                    continue;
                }

                $product = $this->import->fetchProduct($upc);
                if ($product->isExistingProduct) {
                    $this->import->discontinueProduct($storeId, $product->productId);
                } else {
                    $this->import->recordSkipped();
                }
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }

    private function importActiveFile(string $file)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                if (!$this->import->recordRow()) {
                    break;
                }

                $upc = BarcodeFixer::fixLength($data[1]);
                if ($this->import->isInvalidBarcode($upc, $data[2])) {
                    continue;
                }

                $storeId = $this->import->storeNumToStoreId($data[0]);
                if ($storeId === false) {
                    continue;
                }

                $departmentId = $this->import->getDepartmentId($data[2], $data[3]);
                if ($departmentId === false) {
                    continue;
                }

                if ($this->import->isInSkipList($upc)) {
                    continue;
                }

                $product = $this->import->fetchProduct($upc, $storeId);

                if ($product->isExistingProduct) {
                    if ($product->hasInventory()) {
                        $this->import->recordSkipped();
                        continue;
                    }

                    $disco = $this->import->db->hasDiscoInventory($product->productId, $storeId);
                    if ($disco) {
                        $this->import->recordSkipped();
                        continue;
                    }
                }

                $product->setDescription($data[4]);
                $product->setSize($data[5]);

                $this->import->implementationScan(
                    $product,
                    $storeId,
                    'UNKN',
                    '',
                    $departmentId
                );
                $this->persistMetric($upc, $storeId, $data);
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }

    private function persistMetric(string $upc, string $storeId, array $row)
    {
        $product = $this->import->fetchProduct($upc);
        if ($product->isExistingProduct === false) {
            return;
        }

        $this->import->persistMetric(
            $storeId,
            $product->productId,
            $this->import->convertFloatToInt(floatval($row[7])),
            $this->import->convertFloatToInt(floatval($row[6])),
            $this->import->convertFloatToInt(floatval($row[8]))
        );
    }
}
