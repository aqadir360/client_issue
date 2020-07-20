<?php

namespace App\Objects;

use Illuminate\Support\Facades\DB;

class ImportFileStatus
{
    private $companyId;
    private $fileRowId;
    public $filename;

    public $success = 0;
    public $errors = 0;
    public $total = 0;
    public $skipped = 0;
    public $invalidBarcodeErrors = 0;

    public $invalidDepts = [];
    public $invalidStores = [];
    public $invalidBarcodes = [];

    public function __construct($filename, $companyId)
    {
        $this->filename = $filename;
        $this->companyId = $companyId;
    }

    public function outputResults(): string
    {
        $str = "{$this->total} Total Rows";

        if ($this->success > 0) {
            $str .= ", {$this->success} successes";
        }

        if ($this->errors > 0) {
            $str .= ", {$this->errors} errors";
        }

        if ($this->skipped > 0) {
            $str .= ", {$this->skipped} skipped";
        }

        if ($this->invalidBarcodeErrors > 0) {
            $str .= ", {$this->invalidBarcodeErrors} invalid barcodes";
        }

        return $str;
    }

    public function deleteFile()
    {
        if (file_exists($this->filename)) {
            unlink($this->filename);
        }
    }

    public function insertFileRow()
    {
        $sql = "INSERT INTO dcp2admin.import_results (company_id, filename) VALUES (:company_id, :filename)";

        DB::insert($sql, [
            'company_id' => $this->companyId,
            'filename' => basename($this->filename),
        ]);

        $this->fileRowId = DB::getPdo()->lastInsertId();;
    }

    public function updateCompletedRow()
    {
        $sql = "UPDATE dcp2admin.import_results
                SET completed_at = NOW(), success = :success, skipped = :skipped, barcode_errors = :barcode_errors, errors = :errors,
                    total = :total, invalid_depts = :invalid_depts, invalid_stores = :invalid_stores, invalid_barcodes = :invalid_barcodes
                    WHERE id = :id";

        DB::update($sql, [
            'id' => $this->fileRowId,
            'success' => $this->success,
            'skipped' => $this->skipped,
            'barcode_errors' => $this->invalidBarcodeErrors,
            'errors' => $this->errors,
            'total' => $this->total,
            'invalid_depts' => implode(',', $this->invalidDepts),
            'invalid_stores' => implode(',', $this->invalidStores),
            'invalid_barcodes' => implode(',', $this->invalidBarcodes),
        ]);
    }

    public function recordErrorMessage($response)
    {
        if (isset($response['status'])) {
            if ($response['status'] === 'NOT_VALID') {
                $this->invalidBarcodeErrors++;
            } else {
                if (strpos($response['message'], 'Invalid Barcode') !== false) {
                    $this->invalidBarcodeErrors++;
                } else {
                    $this->errors++;
                    $this->insertErrorMessage($response['status'], $response['message']);
                }
            }
        }
    }

    private function insertErrorMessage($status, $message)
    {
        $sql = "INSERT INTO dcp2admin.import_errors (import_id, row, status, message) VALUES (:import_id, :row, :status, :message)";

        DB::insert($sql, [
            'import_id' => $this->fileRowId,
            'row' => $this->total,
            'status' => $status,
            'message' => $message,
        ]);
    }
}
