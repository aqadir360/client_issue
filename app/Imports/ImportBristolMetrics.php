<?php

namespace App\Imports;

use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;

// Bristol Farms Metrics Import
// TODO: Currently using local files, update when FTP files start sending
class ImportBristolMetrics implements ImportInterface
{
    /** @var ImportManager */
    private $import;

    public function __construct(ImportManager $import)
    {
        $this->import = $import;
    }

    public function importUpdates()
    {
        $metricsFiles = glob(storage_path('imports/bristol_01.csv'));

        foreach ($metricsFiles as $file) {
            $this->importMetricsFile($file);
        }

        $this->import->completeImport();
    }

    private function importMetricsFile(string $file)
    {
        $this->import->startNewFile($file);
        $storeId = '13b5c85d-4767-9472-1ce8-627e98c4faeb'; // Rolling Hills

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 10000, "|")) !== false) {
                if (!$this->import->recordRow()) {
                    break;
                }

                $upc = BarcodeFixer::fixUpc(trim($data[0]));
                if ($this->import->isInvalidBarcode($upc, $data[0])) {
                    continue;
                }

                echo $upc . PHP_EOL;

                $product = $this->import->fetchProduct($upc);

                if (!$product->isExistingProduct) {
                    $product->setDescription($data[5]);
                    $product->setSize($data[6]);
                    $this->import->createProduct($product);
                }

                $this->import->persistMetric(
                    $storeId,
                    $product,
                    $this->import->convertFloatToInt(floatval($data[9])),
                    $this->import->convertFloatToInt(floatval($data[8])),
                    $this->import->convertFloatToInt(floatval($data[7]))
                );
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }
}
