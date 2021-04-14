<?php

namespace App\Imports;

use App\Objects\BarcodeFixer;
use App\Objects\ImportManager;

class ImportLeeversMetrics implements ImportInterface
{
    /** @var ImportManager */
    private $import;

    private $stores = [];

    public function __construct(ImportManager $importManager)
    {
        $this->import = $importManager;
    }

    public function importUpdates()
    {
        $importList = glob(storage_path('imports/leevers_metrics.csv'));

        $this->setStores($this->import->companyId());

        foreach ($importList as $file) {
            $this->importMetricsFile($file);
        }

        $this->import->completeImport();
    }

    private function importMetricsFile($file)
    {
        $this->import->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                if ($data[0] === "Store") {
                    continue;
                }

                if (!$this->import->recordRow()) {
                    break;
                }

                $barcode = BarcodeFixer::fixUpc(trim($data[1]));
                if ($this->import->isInvalidBarcode($barcode, $data[1])) {
                    continue;
                }

                $storeId = $this->storeNameToStoreId(strtolower($data[0]));
                if ($storeId === false) {
                    continue;
                }

                $cost = $this->import->parsePositiveFloat($data[8]);
                $retail = $this->import->parsePositiveFloat($data[7]);

                if ($cost > $retail) {
                    $this->import->recordSkipped();
                    continue;
                }

                $movement = $this->import->parsePositiveFloat(floatval($data[6]));

                $product = $this->import->fetchProduct($barcode);
                if ($product->isExistingProduct === false) {
                    $this->import->recordSkipped();
                    continue;
                }

                echo "Adding metric $barcode" . PHP_EOL;

                $this->import->persistMetric(
                    $storeId,
                    $product,
                    $this->import->convertFloatToInt($cost),
                    $this->import->convertFloatToInt($retail),
                    $this->import->convertFloatToInt($movement),
                    true
                );
            }

            fclose($handle);
        }

        $this->import->completeFile();
    }

    private function setStores($companyId)
    {
        $rows = $this->import->db->fetchStores($companyId);

        foreach ($rows as $row) {
            $this->stores[strtolower($row->name)] = $row->store_id;
        }
    }

    private function storeNameToStoreId($storeName)
    {
        if (isset($this->stores[$storeName])) {
            return $this->stores[$storeName];
        }

        return false;
    }
}
