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
        $metricsFiles = glob(storage_path('imports/bristol_metrics.csv'));

        foreach ($metricsFiles as $file) {
            $this->importMetricsFile($file);
        }

        $this->import->completeImport();
    }

    private function importMetricsFile(string $file)
    {
        $this->import->startNewFile($file);
        $storeId = '29e58f03-d843-d2b7-9b09-cf63a89264e4'; // Newport Beach

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                if (!$this->import->recordRow()) {
                    break;
                }

                $upc = BarcodeFixer::fixUpc(trim($data[0]));
                if ($this->import->isInvalidBarcode($upc, $data[0])) {
                    continue;
                }

                $product = $this->import->fetchProduct($upc);
                if ($product->isExistingProduct) {
                    $this->import->persistMetric(
                        $storeId,
                        $product,
                        $this->import->convertFloatToInt(floatval($data[3])),
                        $this->import->convertFloatToInt(floatval($data[2])),
                        $this->import->convertFloatToInt(floatval($data[1]))
                    );
                } else {
                    $this->import->recordSkipped();
                }
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }
}
