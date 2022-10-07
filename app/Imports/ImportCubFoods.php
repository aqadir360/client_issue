<?php

namespace App\Imports;

use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;

// Cub Foods Products and Metrics Import
// TODO: Currently using local files, update when FTP files start sending
class ImportCubFoods implements ImportInterface
{
    /** @var ImportManager */
    private $import;

    public function __construct(ImportManager $import)
    {
        $this->import = $import;
    }

    public function importUpdates()
    {
        $metricsFiles = glob(storage_path('imports/cub/*.csv'));

        foreach ($metricsFiles as $file) {
            $this->importMetricsFile($file);
        }

        $this->import->completeImport();
    }

    private function importMetricsFile(string $file)
    {
        $this->import->startNewFile($file);

        $storeId = 'b80ce99e-f566-743b-4d4b-e7aea27a2e92'; // Stillwater

        $invalid = $valid = $existing = 0;

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 10000, ",")) !== false) {
                if (!$this->import->recordRow()) {
                    break;
                }

                $upc = BarcodeFixer::fixUpc(trim($data[0]));
                if ($this->import->isInvalidBarcode($upc, $data[0])) {
                    $invalid++;
                    continue;
                }

                $product = $this->import->fetchProduct($upc);

                if (!$product->isExistingProduct) {
                    $valid++;
                    $product->setDescription($data[1]);
                    $product->setSize($data[2]);
                    $this->import->createProduct($product);
                } else {
                    $existing++;
                }

                try {
                    $this->import->persistMetric(
                        $storeId,
                        $product,
                        $this->import->convertFloatToInt(floatval($data[5])),
                        $this->import->convertFloatToInt(floatval($data[4])),
                        $this->import->convertFloatToInt(floatval($data[3]))
                    );
                } catch (\Exception $e) {
                    var_dump($product);
                }
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }
}
