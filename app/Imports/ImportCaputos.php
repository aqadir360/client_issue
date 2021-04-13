<?php

namespace App\Imports;

use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;

// Imports Caputos products
class ImportCaputos implements ImportInterface
{
    /** @var ImportManager */
    private $import;

    public function __construct(ImportManager $importManager)
    {
        $this->import = $importManager;
    }

    public function importUpdates()
    {
        $fileList = glob(storage_path('caputos_products.csv'));

        foreach ($fileList as $file) {
            $this->importProducts($file);
        }

        $this->import->completeImport();
    }

    private function importProducts($file)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                if (!$this->import->recordRow()) {
                    continue;
                }

                $upc = BarcodeFixer::fixUpc(trim($data[0]));
                if ($this->import->isInvalidBarcode($upc, $data[0])) {
                    $this->import->writeFileOutput($data, "Skip: Invalid Barcode");
                    continue;
                }

                $product = $this->import->fetchProduct($upc);

                if (!$product->isExistingProduct) {
                    $product->setDescription($data[1]);
                    $product->setNewProductId();
                    echo $upc . " " . $product->productId . PHP_EOL;
                    $this->import->db->insertProduct($product->productId, $product->barcode, $product->description, $product->size);
                }

                $this->import->db->getOrInsertProduct($product);

                $this->import->writeFileOutput($data, "Success: Created Product");
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }
}
