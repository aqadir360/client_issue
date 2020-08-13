<?php

namespace App\Objects;

use App\Models\Product;
use Exception;

class ImportManager
{
    /** @var FtpManager */
    public $ftpManager;

    /** @var FileStatus */
    private $currentFile;

    /** @var Api */
    private $proxy;

    /** @var Database */
    public $db;

    /** @var DepartmentMapper */
    private $departments;

    private $companyId;
    private $importTypeId;
    private $importId;
    private $filesProcessed = 0;

    private $debugMode;

    private $stores = [];
    private $skipList = [];

    private $invalidDepts = [];
    private $invalidStores = [];
    private $invalidBarcodes = [];

    public function __construct(
        Api $api,
        Database $database,
        string $companyId,
        string $ftpPath,
        int $importTypeId,
        int $compareDate,
        bool $debugMode = false
    ) {
        $this->proxy = $api;
        $this->db = $database;
        $this->companyId = $companyId;
        $this->importTypeId = $importTypeId;
        $this->importId = $this->db->startImport($importTypeId);

        $this->setStores();
        $this->setDepartments();

        $this->ftpManager = new FtpManager($ftpPath, $compareDate);
        $this->debugMode = $debugMode;
    }

    public function companyId(): string
    {
        return $this->companyId;
    }

    public function getProxy(): Api
    {
        return $this->proxy;
    }

    public function startNewFile($filePath)
    {
        $this->filesProcessed++;
        $file = basename($filePath);
        $this->currentFile = new FileStatus($filePath);
        $this->currentFile->insertFileRow($this->importId);
        $this->outputContent("---- Importing $file");
    }

    public function recordFileError($status, $message)
    {
        $this->currentFile->recordError($status, $message);
    }

    public function completeFile()
    {
        $this->outputContent($this->currentFile->outputResults());
        $this->currentFile->updateCompletedRow();

        if (!$this->debugMode) {
            $this->currentFile->deleteFile();
        }
    }

    public function downloadFilesByName(string $name, bool $matching = true): array
    {
        $output = [];
        $files = $this->ftpManager->getRecentlyModifiedFiles();
        foreach ($files as $file) {
            if ($matching) {
                if (strpos($file, $name) !== false) {
                    $output[] = $this->ftpManager->downloadFile($file);
                }
            } else {
                if (strpos($file, $name) === false) {
                    $output[] = $this->ftpManager->downloadFile($file);
                }
            }
        }
        return $output;
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

    public function recordRow(): bool
    {
        if ($this->debugMode && $this->currentFile->total + 1 > 1000) {
            return false;
        }

        $this->currentFile->total++;
        return true;
    }

    public function recordSkipped()
    {
        $this->currentFile->skipped++;
    }

    public function recordStatic()
    {
        $this->currentFile->static++;
    }

    public function recordAdd()
    {
        $this->currentFile->adds++;
    }

    public function discontinueProduct(string $storeId, string $productId)
    {
        $response = $this->proxy->discontinueProduct($storeId, $productId);
        $this->recordResponse($response, 'disco');
    }

    public function discontinueProductByBarcode(string $storeId, string $barcode)
    {
        $response = $this->proxy->discontinueProductByBarcode($storeId, $barcode);
        $this->recordResponse($response, 'disco');
    }

    public function persistProduct($barcode, $name, $size): bool
    {
        $response = $this->proxy->persistProduct($barcode, $name, $size);

        if (!$this->proxy->validResponse($response)) {
            $this->addInvalidBarcode($barcode);
            $this->currentFile->invalidBarcodeErrors++;
            return false;
        }

        return true;
    }

    public function implementationScan(Product $product, string $storeId, string $aisle, string $section, string $deptId, string $shelf = '')
    {
        $response = $this->proxy->implementationScan(
            $product,
            $storeId,
            $aisle,
            $section,
            $deptId,
            $shelf
        );
        $this->recordResponse($response, 'add');
    }

    public function updateInventoryLocation(string $itemId, string $storeId, string $deptId, string $aisle, string $section, string $shelf = '')
    {
        $response = $this->proxy->updateInventoryLocation(
            $itemId,
            $storeId,
            $deptId,
            $aisle,
            $section,
            $shelf
        );
        $this->recordResponse($response, 'move');
    }

    public function createVendor(string $barcode, string $vendor)
    {
        $this->proxy->createVendor($barcode, $vendor, $this->companyId);
    }

    public function recordResponse($response, $type)
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
        $this->currentFile->outputContent($msg);
    }

    private function outputContentList($array)
    {
        foreach ($array as $item) {
            echo $item . PHP_EOL;
            $this->currentFile->outputContent($item);
        }
    }

    public function completeImport(string $errorMsg = '')
    {
        $this->proxy->triggerUpdateCounts($this->companyId);
        $this->db->completeImport(
            $this->importId,
            $this->importTypeId,
            $this->filesProcessed,
            $this->ftpManager->getNewDate(),
            $errorMsg
        );

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

    public function persistMetric(string $storeId, string $productId, int $cost, int $retail, int $movement, bool $recordSkipped = false)
    {
        if ($cost === 0 && $retail === 0 && $movement === 0) {
            if ($recordSkipped) {
                $this->currentFile->skipped++;
            }
            return;
        }

        if ($this->debugMode) {
            $this->currentFile->metrics++;
            return;
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
                $this->currentFile->metrics++;
            } elseif ($recordSkipped) {
                $this->currentFile->skipped++;
            }
        } else {
            $this->db->insertMetric($storeId, $productId, $cost, $retail, $movement);
            $this->currentFile->metrics++;
        }
    }
}
