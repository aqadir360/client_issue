<?php

namespace App\Objects;

use App\Models\Product;
use Exception;

class ImportManager
{
    /** @var FileStatus */
    public $currentFile;

    /** @var Database */
    private $db;

    /** @var DepartmentMapper */
    private $departments;

    private $companyId;
    private $importId;

    private $stores = [];
    private $skipList = [];

    private $invalidDepts = [];
    private $invalidStores = [];
    private $invalidBarcodes = [];

    public function __construct(Database $database, string $companyId)
    {
        $this->db = $database;
        $this->companyId = $companyId;
        $this->importId = $this->db->startImport($this->companyId);

        $this->setStores();
        $this->setDepartments();
    }

    public function startNewFile($filePath)
    {
        $file = basename($filePath);
        $this->outputContent("---- Importing $file");
        $this->currentFile = new FileStatus($filePath, $this->companyId);
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

    private function setStores()
    {
        $rows = $this->db->fetchStores($this->companyId);

        foreach ($rows as $row) {
            if (!empty($row->store_num)) {
                $this->stores[$row->store_num] = $row->store_id;
            }
        }
    }

    private function setDepartments()
    {
        $this->departments = new DepartmentMapper(
            $this->db->fetchDepartments($this->companyId)
        );
    }

    public function setSkipList()
    {
        $rows = $this->db->fetchSkipItems($this->companyId);
        foreach ($rows as $row) {
            $this->skipList[intval($row->barcode)] = true;
        }
    }

    public function isInSkipList($barcode): bool
    {
        if (isset($this->skip[intval($barcode)])) {
            $this->currentFile->skipList++;
            return true;
        }

        return false;
    }

    public function addToSkipList($barcode): bool
    {
        if ($barcode === '' || intval($barcode) === 0) {
            return false;
        }

        if (isset($this->skipList[intval($barcode)])) {
            return false;
        }

        $this->db->insertSkipItem($this->companyId, $barcode);
        $this->skipList[intval($barcode)] = true;

        try {
            $parsed = BarcodeFixer::fixUpc($barcode);
            if (intval($parsed) !== intval($barcode) && !(isset($this->skipList[intval($parsed)]))) {
                $this->db->insertSkipItem($this->companyId, $parsed);
                $this->skipList[intval($parsed)] = true;
            }
        } catch (Exception $e) {
            // Ignore invalid
        }

        return true;
    }

    public function getDepartmentId(string $department, string $category = '')
    {
        $dept = $this->departments->getMatchingDepartment($department, $category);

        if ($dept === null) {
            $this->addInvalidDepartment($department);
            $this->currentFile->skipDepts++;
            return false;
        }

        if ($dept->wildcardDeptMatch) {
            // Record unmatched departments
            $this->addInvalidDepartment($department);
        }

        if ($dept->skip || $dept->departmentId === null) {
            $this->currentFile->skipDepts++;
            return false;
        }

        return $dept->departmentId;
    }

    public function storeNumToStoreId($storeNum)
    {
        if (isset($this->stores[$storeNum])) {
            return $this->stores[$storeNum];
        }

        $this->currentFile->skipStores++;
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
        $this->invalidBarcodes[intval($barcode)] = utf8_encode($barcode);
        $this->currentFile->invalidBarcodes[intval($barcode)] = utf8_encode($barcode);
    }

    public function isInvalidBarcode($barcode, $original): bool
    {
        if (isset($this->invalidBarcodes[intval($original)]) || isset($this->invalidBarcodes[intval($barcode)])) {
            $this->currentFile->invalidBarcodeErrors++;
            return true;
        }

        if (BarcodeFixer::isValid($barcode)) {
            return false;
        }

        $this->currentFile->invalidBarcodeErrors++;
        $this->addInvalidBarcode($original);
        return true;
    }

    public function fetchProduct(string $upc, ?string $storeId = null): Product
    {
        $product = new Product($upc);

        $existing = $this->db->fetchProductByBarcode($product->barcode);

        if ($existing !== false) {
            $product->isExistingProduct = true;
            $product->productId = $existing->product_id;

            if ($storeId !== null) {
                $product->inventory = $this->db->fetchProductInventory($product->productId, $storeId);
            }
        }

        return $product;
    }

    public function recordRow()
    {
        $this->currentFile->total++;
    }

    public function recordDisco($response)
    {
        $this->recordResponse($response, 'disco');
    }

    public function recordAdd($response)
    {
        $this->recordResponse($response, 'add');
    }

    public function recordMove($response)
    {
        $this->recordResponse($response, 'move');
    }

    public function recordMetric($response)
    {
        $this->recordResponse($response, 'metric');
    }

    private function recordResponse($response, $type)
    {
        if ($response === null || $response === false) {
            $this->currentFile->errors++;
        } elseif ($this->validResponse($response)) {
            switch ($type) {
                case 'add':
                    $this->currentFile->adds++;
                    break;
                case 'disco':
                    $this->currentFile->discos++;
                    break;
                case 'move':
                    $this->currentFile->moves++;
                    break;
                case 'metric':
                    $this->currentFile->metrics++;
                    break;
            }
        } else {
            $this->currentFile->recordErrorMessage($response);
        }
    }

    private function validResponse($response): bool
    {
        return ($response && ($response === true || $response->status === 'ACCEPTED' || $response->status === 'FOUND'));
    }

    public function outputContent($msg)
    {
        echo $msg . PHP_EOL;
    }

    private function outputContentList($array)
    {
        foreach ($array as $item) {
            echo $item . PHP_EOL;
        }
    }

    public function completeImport()
    {
        $this->db->completeImport($this->importId);

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

    public function parsePositiveFloat($value): float
    {
        $float = floatval($value);
        return $float < 0 ? 0 : $float;
    }

    public function convertFloatToInt(float $value): int
    {
        return intval($value * 1000);
    }

    public function persistMetric(string $storeId, string $productId, int $cost, int $retail, int $movement): bool
    {
        if ($cost === 0 && $retail === 0 && $movement === 0) {
            return false;
        }

        $existing = $this->db->fetchExistingMetric($storeId, $productId);

        if (count($existing) > 0) {
            $existingCost = intval($existing[0]->cost);
            $existingRetail = intval($existing[0]->retail);
            $existingMovement = intval($existing[0]->movement);

            // Do not overwrite existing values with zero
            $cost = ($cost > 0) ? $cost : $existingCost;
            $retail = ($retail > 0) ? $retail : $existingRetail;
            $movement = ($movement > 0) ? $movement : $existingMovement;

            if ($cost !== $existingCost || $retail !== $existingRetail || $movement !== $existingMovement) {
                $this->db->updateMetric($storeId, $productId, $cost, $retail, $movement);
            } else {
                return false;
            }
        } else {
            $this->db->insertMetric($storeId, $productId, $cost, $retail, $movement);
        }

        return true;
    }
}

