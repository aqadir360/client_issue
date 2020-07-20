<?php

namespace App\Objects;

class ImportStatusOutput
{
    /** @var ImportFileStatus */
    public $currentFile;

    private $content = '';
    private $companyId;
    private $companyName;

    private $stores = [];

    private $invalidDepts = [];
    private $invalidStores = [];
    private $invalidBarcodes = [];

    public function __construct($companyId, $companyName)
    {
        $this->companyId = $companyId;
        $this->companyName = $companyName;
    }

    public function startNewFile($filePath)
    {
        $file = basename($filePath);
        $this->outputContent("---- Importing $file");
        $this->currentFile = new ImportFileStatus($filePath, $this->companyId);
        $this->currentFile->insertFileRow();
    }

    public function completeFile()
    {
        $this->outputContent($this->currentFile->outputResults());
        $this->currentFile->updateCompletedRow();
        $this->currentFile->deleteFile();
    }

    public function addInvalidStore($storeId)
    {
        $this->invalidStores[$storeId] = $storeId;
        $this->currentFile->invalidStores[$storeId] = $storeId;
    }

    public function setStores(Api $api)
    {
        $response = $api->fetchAllStores($this->companyId);
        foreach ($response->stores as $store) {
            if (!empty($store->storeNum)) {
                $this->stores[$store->storeNum] = $store->storeId;
            }
        }
    }

    public function storeNumToStoreId($storeNum)
    {
        if (isset($this->stores[$storeNum])) {
            return $this->stores[$storeNum];
        }

        $this->currentFile->errors++;
        $this->addInvalidStore($storeNum);
        return false;
    }

    public function addInvalidDepartment($department)
    {
        $this->invalidDepts[$department] = $department;
        $this->currentFile->invalidDepts[$department] = $department;
    }

    public function addInvalidBarcode($barcode)
    {
        $this->invalidBarcodes[intval($barcode)] = $barcode;
        $this->currentFile->invalidBarcodes[intval($barcode)] = $barcode;
    }

    public function isInvalidBarcode($barcode): bool
    {
        if (isset($this->invalidBarcodes[intval($barcode)])) {
            $this->currentFile->invalidBarcodeErrors++;
            return true;
        }

        return false;
    }

    // If product exists, returns valid product
    // If invalid barcode, returns null
    // If valid barcode with no existing product, returns false
    public function fetchProduct(Api $proxy, string $upc, ?string $storeId = null)
    {
        $response = $proxy->fetchProduct($upc, $this->companyId, $storeId);

        if ($response['status'] == "FOUND" && !empty($response['product'])) {
            return $response['product'];
        } elseif ($response['message'] == 'Invalid Barcode') {
            $this->addInvalidBarcode($upc);
            $this->currentFile->invalidBarcodeErrors++;
            return null;
        }

        return false;
    }

    public function recordRow()
    {
        $this->currentFile->total++;
    }

    // Records boolean or API response results to current file counts
    public function recordResult($response): bool
    {
        if ($response === true) {
            $this->currentFile->success++;
            return true;
        }

        if ($response === false) {
            $this->currentFile->errors++;
            return false;
        }

        if ($this->validResponse($response)) {
            $this->currentFile->success++;
            return true;
        } else {
            $this->currentFile->recordErrorMessage($response);
            return false;
        }
    }

    private function validResponse($response)
    {
        return ($response['status'] === 'ACCEPTED' || $response['status'] === 'FOUND');
    }

    public function outputContent($msg)
    {
        echo $msg . PHP_EOL;
        $this->content .= "<p>" . $msg . "</p> ";
    }

    private function outputContentList($array)
    {
        $this->content .= "<ul>";

        foreach ($array as $item) {
            echo $item . PHP_EOL;
            $this->content .= "<li>" . $item . "</li> ";
        }

        $this->content .= "</ul>";
    }

    public function outputResults()
    {
        if (count($this->invalidStores) > 0) {
            $this->outputContent("Invalid Stores:");
            $this->outputContentList($this->invalidStores);
        }

        if (count($this->invalidDepts) > 0) {
            $this->outputContent("Invalid Departments:");
            $this->outputContentList($this->invalidDepts);
        }

        if (count($this->invalidBarcodes) > 0) {
            $this->outputContent("Invalid Barcodes:");
            $this->outputContentList($this->invalidBarcodes);
        }
    }
}

