<?php

namespace App\Objects;

use App\Models\Department;
use App\Models\Product;
use Illuminate\Support\Facades\Storage;
use Ramsey\Uuid\Uuid;

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

    /** @var SkippedLocations */
    private $skippedLocations = null;

    private $companyId;
    private $importTypeId;
    private $importStatusId;
    private $filesProcessed = 0;
    private $outputFile;
    private $outputUploadFileName;

    private $debugMode;

    private $stores = [];
    private $skipList = [];
    private $categories = [];

    private $invalidDepts = [];
    private $invalidStores = [];
    private $invalidBarcodes = [];
    private $mappedDepartments = [];

    private $products = [];

    public function __construct(
        Api $api,
        Database $database,
        FtpManager $ftpManager,
        string $companyId,
        string $dbName,
        int $importTypeId,
        ?int $importJobId,
        bool $debugMode
    ) {
        $this->companyId = $companyId;
        $this->importTypeId = $importTypeId;

        $this->db = $database;
        $this->db->setDbName($dbName);

        $this->proxy = $api;
        $this->proxy->setAdminToken(
            $this->db->setProxyLoginToken($companyId)
        );

        $this->importStatusId = $this->db->startImport($importTypeId, $importJobId);
        $this->setStores();
        $this->setDepartments();

        $this->ftpManager = $ftpManager;
        $this->debugMode = $debugMode;
        $this->currentFile = new FileStatus('');

        if ($this->debugMode) {
            echo "* DEBUG MODE *" . PHP_EOL;
        }
    }

    public function companyId(): string
    {
        return $this->companyId;
    }

    public function getProxy(): Api
    {
        return $this->proxy;
    }

    public function setSkippedLocations(SkippedLocations $skip)
    {
        $this->skippedLocations = $skip;
    }

    public function shouldSkipLocation($aisle, $section = '', $shelf = ''): bool
    {
        if (empty($aisle) && empty($section)) {
            if ($this->currentFile) {
                $this->currentFile->skipped++;
            }
            return true;
        }

        if ($this->skippedLocations === null) {
            return false;
        }

        $skip = $this->skippedLocations->shouldSkip($aisle, $section, $shelf);
        if ($skip === true && $this->currentFile) {
            $this->currentFile->skipped++;
        }
        return $skip;
    }

    public function startNewFile($filePath, string $append = '')
    {
        $this->filesProcessed++;
        $file = basename($filePath);
        $outputFileName = $file . $append . time() . '-output.csv';
        $this->outputUploadFileName = $outputFileName;
        $this->currentFile = new FileStatus($filePath);
        $this->currentFile->insertFileRow($this->importStatusId, date("Y") . "\/" . date('m') . "\/" . $outputFileName);
        $this->outputContent("---- Importing $file $append");

        $this->mappedDepartments = [];

        if (!Storage::disk('imports_output')->exists('upload/')) {
            Storage::disk('imports_output')->makeDirectory('upload/');
        }

        $this->outputFile = fopen(storage_path('output/' . $outputFileName), 'w');
    }

    public function recordFileLineError($status, $message)
    {
        $this->currentFile->recordError($status, $message);
    }

    public function writeFileOutput(array $data, string $message)
    {
        array_unshift($data, $message);
        fputcsv($this->outputFile, $data);
    }

    public function completeFile(bool $deleteFile = true)
    {
        $this->outputContent($this->currentFile->outputResults());
        $this->currentFile->updateCompletedRow();

        if ($deleteFile && !$this->debugMode) {
            $this->currentFile->deleteFile();
        }

        fclose($this->outputFile);

        $outFiles = Storage::disk('imports_output');
        $fileExists = $outFiles->exists($this->outputUploadFileName);
        if ($fileExists) {
            $fileSize = filesize(storage_path('output/' . $this->outputUploadFileName));
            if ($fileSize < 5) {
                $outFiles->delete($this->outputUploadFileName);
            } else {
                Storage::disk('imports_output_files')->put($this->outputUploadFileName, $outFiles->get($this->outputUploadFileName));
                $outFiles->delete($this->outputUploadFileName);
            }
        }
    }

    public function outputAndResetFile()
    {
        $this->outputContent($this->currentFile->outputResults());
        $this->currentFile->resetCounts();
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

    public function downloadStoreFileByName($files, string $name, string $storeNum): ?string
    {
        foreach ($files as $file) {
            if (strpos($file, $name) !== false && strpos($file, $storeNum) !== false) {
                return $this->ftpManager->downloadFile($file);
            }
        }
        return null;
    }

    public function downloadMostRecentMatchingFile(array $files, string $name): ?string
    {
        $lastModified = 0;
        $mostRecentFile = null;
        foreach ($files as $file) {
            if (strpos($file, $name) !== false) {
                $fileLastModified = Storage::disk('sftp')->lastModified($file);
                if (($lastModified < $fileLastModified)) {
                    $lastModified = $fileLastModified;
                    $mostRecentFile = $file;
                }
            }
        }
        if ($mostRecentFile !== null) {
            return $this->ftpManager->downloadFile($mostRecentFile);
        }
        return null;
    }

    public function getStores(): array
    {
        return $this->stores;
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

    // Populates company barcode skip list
    public function setSkipList()
    {
        $rows = $this->db->fetchSkipItems($this->companyId);
        foreach ($rows as $row) {
            $this->skipList[intval($row->barcode)] = true;
        }
    }

    // Checks for barcode in skip list
    // Increments skipList and returns true if found
    public function isInSkipList($barcode): bool
    {
        if (isset($this->skipList[intval($barcode)])) {
            if ($this->currentFile) {
                $this->currentFile->skipList++;
            }
            return true;
        }

        return false;
    }

    // Inserts barcode to skip list table if not found in array
    public function addToSkipList($barcode): bool
    {
        if ($barcode === '' || intval($barcode) === 0) {
            return false;
        }

        if (isset($this->skipList[intval($barcode)])) {
            return false;
        }

        $this->insertSkipItem($barcode);

        // Use barcode as-is
        $upcOne = str_pad(ltrim($barcode, '0'), 13, '0', STR_PAD_LEFT);
        if (BarcodeFixer::isValid($upcOne) && !(isset($this->skipList[intval($upcOne)]))) {
            $this->insertSkipItem($upcOne);
        }

        // Add a check digit
        $upcTwo = str_pad(ltrim($barcode, '0'), 11, '0', STR_PAD_LEFT);
        $upcTwo = '0' . $upcTwo . BarcodeFixer::calculateMod10Checksum($upcTwo);
        if (BarcodeFixer::isValid($upcTwo) && !(isset($this->skipList[intval($upcTwo)]))) {
            $this->insertSkipItem($upcTwo);
        }

        return true;
    }

    private function insertSkipItem(string $barcode)
    {
        $this->db->insertSkipItem($this->companyId, $barcode);
        $this->skipList[intval($barcode)] = true;
    }

    public function setCategories()
    {
        $categories = $this->db->fetchCategories();
        foreach ($categories as $category) {
            $this->categories[$category->department . $category->name] = $category->category_id;
        }
    }

    public function getDeptIdAndRecordCategory(Product $product, string $department, string $category = '')
    {
        $this->recordCategory($product, $department, $category);
        return $this->getDepartmentId(strtolower($department), strtolower($category), $product->barcode);
    }

    public function recordCategory(Product $product, string $department, string $category)
    {
        if (isset($this->categories[$department . $category])) {
            $categoryId = $this->categories[$department . $category];
        } else {
            $categoryId = (string)Uuid::uuid1();
            $this->db->insertCategory($categoryId, $department, $category);
            $this->categories[$department . $category] = $categoryId;
        }

        if ($product->categoryId !== $categoryId) {
            $this->db->updateProductCategory($product->productId, $categoryId);
            if (isset($this->products[intval($product->barcode)])) {
                $product->categoryId = $categoryId;
                $this->products[intval($product->barcode)] = $product;
            }
        }
    }

    // Finds matching department id by company department mappings
    // Increments skipped or skipDepts and returns false if invalid
    public function getDepartmentId(string $department, string $category = '', string $upc = '')
    {
        $dept = $this->departments->getMatchingDepartment($department, $category);

        if ($dept === null) {
            $this->writeFileOutput([$upc, $department, $category], "Department Rule NOT Mapped");
            $this->addInvalidDepartment(trim($department . ' ' . $category));
            $this->currentFile->skipDepts++;
            return false;
        }

        if ($dept->wildcardDeptMatch) {
            // Record unmatched departments
            $this->addInvalidDepartment(trim("Wildcard Match: " . $department . ' ' . $category));
        }

        if ($dept->skip) {
            $this->outputDepartmentMapping($department, $category, $dept);
            $this->currentFile->skipped++;
            return false;
        }

        if ($dept->departmentId === null) {
            $this->outputDepartmentMapping($department, $category, $dept);
            $this->currentFile->skipDepts++;
            return false;
        }

        $this->outputDepartmentMapping($department, $category, $dept);
        return $dept->departmentId;
    }

    private function outputDepartmentMapping(string $department, string $category, Department $dept)
    {
        if (!isset($this->mappedDepartments[$department . " " . $category])) {
            $this->db->insertDepartmentMapped(
                $this->currentFile->getFileRowId(),
                $department,
                $category,
                $dept->departmentId,
                $dept->departmentRule,
                $dept->categoryRule,
                $dept->skip
            );
            $this->mappedDepartments[$department . " " . $category] = true;
        }
    }

    public function getBristolFarmsLaStorId(){ // fetch store id containing "LA" from bristol company db

        return $this->db->fetchAllBristolFarmsLaStorId($this->companyId);
    }

    // Finds store id by store number mapping
    // Increments skipStores and returns false if invalid
    public function storeNumToStoreId($storeNum)
    {
        if (isset($this->stores[$storeNum])) {
            return $this->stores[$storeNum];
        }

        $this->currentFile->skipStores++;
        $this->addInvalidStore($storeNum);
        return false;
    }

    // Adds invalid department to list
    public function addInvalidDepartment($department)
    {
        $this->invalidDepts[$department] = $department;
        $this->currentFile->invalidDepts[$department] = $department;
    }

    // Adds invalid barcode to list
    public function addInvalidBarcode($barcode)
    {
        $this->invalidBarcodes[intval($barcode)] = utf8_encode($barcode);
        $this->currentFile->invalidBarcodes[intval($barcode)] = utf8_encode($barcode);
    }

    // Checks for invalid barcode, returns false if valid
    // Increments invalidBarcodeErrors and records barcode to list if invalid
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

    // Populates product object by barcode, setting isExistingProduct = true if found
    // Sets store inventory if storeId is not null
    // Gets the company product or inserts from core table if existing
    public function fetchProduct(string $upc, ?string $storeId = null, ?string $sku = null): Product
    {
        $product = new Product($upc);
        $companyProduct = $this->db->fetchCompanyProductByBarcode($upc);

        // If product exists in company db, populate and check for inventory
        if ($companyProduct) {
            $product->setExistingProduct(
                $companyProduct->product_id,
                $companyProduct->barcode,
                $companyProduct->sku,
                $companyProduct->description,
                $companyProduct->size,
                $companyProduct->photo,
                $companyProduct->category_id,
                $companyProduct->no_expiration,
                $companyProduct->created_at,
                $companyProduct->updated_at,
                $companyProduct->vendor
            );

            if ($storeId !== null) {
                $product->inventory = $this->db->fetchProductInventory($product->productId, $storeId);
            }

            return $product;
        }

        // If product exists in core db, copy to company db
        $product = $this->fetchCoreProduct($upc);
        if ($product->isExistingProduct) {
            $product->sku = $sku;
            $this->db->insertCompanyProduct($product);
        }

        return $product;
    }

    // Populates product object by barcode, setting isExistingProduct = true if found
    private function fetchCoreProduct(string $upc): Product
    {
        $product = new Product($upc);
        $existing = $this->db->fetchProductByBarcode($product->barcode);

        if ($existing !== false) {
            $product->setExistingProduct(
                $existing->product_id,
                $existing->barcode,
                null,
                $existing->description,
                $existing->size,
                $existing->photo,
                $existing->category_id,
                $existing->no_expiration,
                $existing->created_at,
                $existing->updated_at
            );
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


    public function recordMove()
    {
        $this->currentFile->moves++;
    }

    public function discontinueInventory(string $itemId)
    {
        $response = $this->proxy->writeInventoryDisco($this->companyId, $itemId);
        $this->recordResponse($response, 'disco');
    }

    public function discontinueProduct(string $storeId, string $productId)
    {
        $response = $this->proxy->discontinueProduct($this->companyId, $storeId, $productId);
        $this->recordResponse($response, 'disco');
    }

    public function discontinueProductByBarcode(string $storeId, string $barcode)
    {
        $response = $this->proxy->discontinueProductByBarcode($this->companyId, $storeId, $barcode);
        $this->recordResponse($response, 'disco');
    }

    // Returns null or product ID
    public function createProduct(Product $product): ?string
    {
        if (!$product->isExistingProduct) {
            $product->setProductId((string)Uuid::uuid1());
        }

        if ($product->invalidProduct()) {
            return null;
        }

        $response = $this->proxy->persistProduct(
            $this->companyId,
            $product->productId,
            $product->barcode,
            $product->description,
            $product->size
        );

        if (!$this->proxy->validResponse($response)) {
            $this->addInvalidBarcode($product->barcode);
            $this->currentFile->invalidBarcodeErrors++;
            return null;
        }

        return $product->productId;
    }

    // Returns product id or null
    public function implementationScan(
        Product $product,
        string $storeId,
        string $aisle,
        string $section,
        string $deptId,
        string $shelf = '',
        bool $skipDisco = false
    ) {
        $response = $this->proxy->implementationScan(
            $product,
            $this->companyId,
            $storeId,
            $aisle,
            $section,
            $deptId,
            $shelf,
            $skipDisco
        );

        $success = $this->recordResponse($response, 'add');
        if ($success && $product->isExistingProduct === false) {
            $this->currentFile->newproducts++;
        }

        if ($success && isset($response->product)) {
            return $response->product->productId;
        }

        return null;
    }

    public function updateInventoryLocation(
        string $itemId,
        string $storeId,
        string $deptId,
        string $aisle,
        string $section,
        string $shelf = ''
    ): bool {
        $response = $this->proxy->updateInventoryLocation(
            $this->companyId,
            $itemId,
            $storeId,
            $deptId,
            $aisle,
            $section,
            $shelf
        );
        return $this->recordResponse($response, 'move');
    }

    public function createVendor(Product $product, string $vendor)
    {
        if ($product->vendor !== $vendor) {
            $this->proxy->createVendor($product->productId, $vendor, $this->companyId);
        }
    }

    public function recordResponse($response, $type): bool
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
            return true;
        } else {
            $this->currentFile->recordErrorMessage($response);
        }
        return false;
    }

    private function validResponse($response): bool
    {
        return ($response && ($response === true || $response->status === 'ACCEPTED' || $response->status === 'FOUND'));
    }

    public function setTotalCount(int $total)
    {
        if ($this->currentFile) {
            $this->currentFile->total = $total;
        }
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
            $this->importStatusId,
            $this->filesProcessed,
            $this->debugMode ? 0 : $this->ftpManager->getNewDate(),
            $errorMsg
        );

        if (count($this->invalidStores) > 0) {
            $this->outputContent("Invalid Stores:");
            $this->outputContentList($this->invalidStores);
        }

        if (count($this->invalidDepts) > 0) {
            $this->outputContent("Unmatched Departments:");
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

    public function persistMetric(
        string $storeId,
        Product $product,
        int $cost,
        int $retail,
        int $movement,
        bool $recordSkipped = false
    ) {
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

        $existing = $this->db->fetchExistingMetric($storeId, $product->productId);
        if (count($existing) > 0) {
            $existingCost = intval($existing[0]->cost);
            $existingRetail = intval($existing[0]->retail);
            $existingMovement = intval($existing[0]->movement);

            // Do not overwrite existing values with zero
            $cost = ($cost > 0) ? $cost : $existingCost;
            $retail = ($retail > 0) ? $retail : $existingRetail;
            $movement = ($movement > 0) ? $movement : $existingMovement;

            if ($cost !== $existingCost || $retail !== $existingRetail || $movement !== $existingMovement) {
                $this->db->updateMetric($storeId, $product->productId, $cost, $retail, $movement);
                $this->currentFile->metrics++;
            } elseif ($recordSkipped) {
                $this->currentFile->skipped++;
            }
        } else {
            $this->db->insertMetric($storeId, $product->productId, $cost, $retail, $movement);
            $this->currentFile->metrics++;
        }
    }

    // Saves products in local array to avoid multiple database lookups
    public function getOrCreateProduct(string $upc, string $description, string $size, ?string $sku = null): ?Product
    {
        if (isset($this->products[intval($upc)])) {
            return $this->products[intval($upc)];
        }

        $product = $this->fetchProduct($upc, null, $sku);
        if (!$product->isExistingProduct) {
            $product->setDescription(trim($description));
            $product->setSize(trim($size));

            $productId = $this->createProduct($product);

            if ($productId === null) {
                $this->writeFileOutput([$upc], "Skip: Could not create product");
                $this->products[intval($upc)] = null;
                return null;
            }

            $product->setProductId($productId);

            if ($sku) {
                $this->db->setProductSku($productId, $sku);
            }
        } elseif ($sku && intval($product->sku) !== intval($sku)) {
            $this->db->setProductSku($product->productId, $sku);
        }

        $this->products[intval($upc)] = $product;

        return $product;
    }
}
