<?php

namespace App\Imports;

use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;

// B Green Products and Metrics Import
// TODO: Currently using local files, update when FTP files start sending
// Barcode|Aisle|Section|Shelf|Department Name|Category|Product Description|Product Size|90 day average daily units sold|Retail Price|Cost
class ImportBGreen implements ImportInterface
{
    /** @var ImportManager */
    private $import;

    public function __construct(ImportManager $import)
    {
        $this->import = $import;
    }

    public function importUpdates()
    {
        $metricsFiles = glob(storage_path('imports/*Green_Valley*'));

        foreach ($metricsFiles as $file) {
            $this->importMetricsFile($file);
        }

        $this->import->completeImport();
    }

    private function importMetricsFile(string $file)
    {
        $this->import->startNewFile($file);

        $storeId = $this->import->storeNumToStoreId($this->parseStoreNum(basename($file)));

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 10000, "|")) !== false) {
                if (!$this->import->recordRow()) {
                    break;
                }

                $upc = BarcodeFixer::fixUpc(trim($data[0]));
                if ($this->import->isInvalidBarcode($upc, $data[0])) {
                    continue;
                }

                $product = $this->import->fetchProduct($upc);

                if (!$product->isExistingProduct) {
                    $product->setDescription($data[6]);
                    $product->setSize($data[7]);
                    $this->import->createProduct($product);
                }

                try {
                    $this->import->persistMetric(
                        $storeId,
                        $product,
                        $this->import->convertFloatToInt(floatval($data[10])),
                        $this->import->convertFloatToInt(floatval($data[9])),
                        $this->import->convertFloatToInt(floatval($data[8]))
                    );
                } catch (\Exception $e) {
                    var_dump($product);
                }
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }

    private function parseStoreNum(string $file)
    {
        return intval(substr($file, 0, strpos($file, '-')));
    }
}
