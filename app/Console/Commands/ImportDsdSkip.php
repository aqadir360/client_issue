<?php

namespace App\Console\Commands;

use App\Objects\Api;
use App\Objects\BarcodeFixer;
use App\Objects\Database;
use Log;
use Illuminate\Console\Command;

// Gets the next closest date for the given product set
class ImportDsdSkip extends Command
{
    protected $signature = 'dcp:import_dsd';
    protected $description = 'Fills in product descriptions for skip list';

    /** @var Api */
    private $proxy;

    /** @var Database */
    private $db;

    private $skus = [];
    private $invalidBarcodes = [];

    public function handle()
    {
        $this->db = new Database();
        $this->db->setDbName('all_companies_db');
        $this->proxy = new Api();
        $this->setSkus();

        $file = storage_path('imports/seg/seg_dsd.csv');
        $outputFile = fopen(storage_path('imports/seg/seg_dsd_output.csv'), 'w');

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                if ($data[0] == 'WAREHOUSE_ITEM') {
                    continue;
                }

                if (intval($data[4]) !== 1) {
                    fputcsv($outputFile, $data);
                    continue;
                }

                $sku = trim($data[0]);
                if (!isset($this->skus[intval($sku)])) {
                    fputcsv($outputFile, $data);
                    continue;
                }

//                $barcode = BarcodeFixer::fixUpc($inputBarcode);

//                if (isset($this->invalidBarcodes[intval($barcode)])) {
//                    continue;
//                }
//
//                if (!BarcodeFixer::isValid($barcode)) {
//                    $this->invalidBarcodes[intval($barcode)] = $barcode;
//                    echo "Invalid " . $barcode . PHP_EOL;
//                    continue;
//                }

//                $this->recordSku($sku, $inputBarcode, $barcode);

                $storeNum = trim($data[1]);

                $this->recordDsdItem($storeNum, $sku);
            }
        }
    }

    private function recordDsdItem($storeNum, $sku)
    {
        $this->db->insertSegDsd($sku, $storeNum);
    }

    private function recordSku($sku, $inputBarcode, $barcode)
    {
        if (!isset($this->skus[intval($sku . $barcode)])) {
            echo $sku . PHP_EOL;
            $this->skus[intval($sku . $barcode)] = true;
            $this->db->insertSegSku($sku, $inputBarcode, $barcode);
        }
    }

    private function setSkus()
    {
        $rows = $this->db->fetchSegSkus();

        foreach ($rows as $row) {
//            $this->skus[intval($row->sku . $row->barcode)] = $row->sku;
            $this->skus[intval($row->sku)] = $row->sku;
        }
    }

}
