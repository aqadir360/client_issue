<?php

namespace App\Imports;

use App\Models\Product;
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
        $activeFiles = $this->import->downloadFilesByName('Sales');

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

                if ($this->import->isInSkipList($upc)) {
                    continue;
                }

                $storeId = $this->import->storeNumToStoreId($data[0]);
                if ($storeId === false) {
                    continue;
                }

                $departmentId = $this->import->getDepartmentId(trim($data[2]), trim($data[3]));
                if ($departmentId === false) {
                    continue;
                }

                $product = $this->import->fetchProduct($upc, $storeId);

                if ($product->isExistingProduct) {
                    $this->handleExistingProduct($product, $storeId, $departmentId, $data);
                } else {
                    $this->handleNewProduct($product, $storeId, $departmentId, $data);
                }
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }

    private function handleExistingProduct(Product $product, string $storeId, string $departmentId, array $data)
    {
        $this->persistMetric($product, $storeId, $data);

        if ($product->hasInventory() || $this->import->db->hasDiscoInventory($product->productId, $storeId)) {
            $this->import->recordSkipped();
            return;
        }

        $this->import->implementationScan(
            $product,
            $storeId,
            'UNKN',
            '',
            $departmentId
        );
    }

    private function handleNewProduct(Product $product, string $storeId, string $departmentId, array $data)
    {
        $product->setDescription($data[4]);
        $product->setSize($data[5]);

        $this->import->implementationScan(
            $product,
            $storeId,
            'UNKN',
            '',
            $departmentId
        );

        $product = $this->import->fetchProduct($product->barcode);

        if ($product->isExistingProduct) {
            $this->persistMetric($product, $storeId, $data);
        }
    }

    private function persistMetric(Product $product, string $storeId, array $row)
    {
        $this->import->persistMetric(
            $storeId,
            $product->productId,
            $this->import->convertFloatToInt(floatval($row[7])),
            $this->import->convertFloatToInt(floatval($row[6])),
            $this->import->convertFloatToInt(floatval($row[8]))
        );
    }
}
