<?php

namespace App\Objects;

use Illuminate\Support\Facades\DB;

class FileStatus
{
    private $companyId;
    private $fileRowId;
    public $filename;

    public $adds = 0;
    public $moves = 0;
    public $discos = 0;
    public $metrics = 0;
    public $errors = 0;
    public $total = 0;
    public $static = 0;
    public $skipped = 0;
    public $skipList = 0;
    public $skipStores = 0;
    public $skipDepts = 0;
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
        $unaccountedRows = $this->total;

        $str = "{$this->total} Total Rows";

        if ($this->adds > 0) {
            $str .= ", {$this->adds} items added";
            $unaccountedRows -= $this->adds;
        }

        if ($this->moves > 0) {
            $str .= ", {$this->moves} items moved";
            $unaccountedRows -= $this->moves;
        }

        if ($this->discos > 0) {
            $str .= ", {$this->discos} items discontinued";
            $unaccountedRows -= $this->discos;
        }

        if ($this->metrics > 0) {
            $str .= ", {$this->metrics} metrics";
            $unaccountedRows -= $this->metrics;
        }

        if ($this->errors > 0) {
            $str .= ", {$this->errors} errors";
            $unaccountedRows -= $this->errors;
        }

        if ($this->skipped > 0) {
            $str .= ", {$this->skipped} skipped";
            $unaccountedRows -= $this->skipped;
        }

        if ($this->skipDepts > 0) {
            $str .= ", {$this->skipDepts} skipped departments";
            $unaccountedRows -= $this->skipDepts;
        }

        if ($this->skipList > 0) {
            $str .= ", {$this->skipList} skip list items";
            $unaccountedRows -= $this->skipList;
        }

        if ($this->static > 0) {
            $str .= ", {$this->static} static items";
            $unaccountedRows -= $this->static;
        }

        if ($this->invalidBarcodeErrors > 0) {
            $str .= ", {$this->invalidBarcodeErrors} invalid barcodes";
            $unaccountedRows -= $this->invalidBarcodeErrors;
        }

        if ($unaccountedRows > 0) {
            $str .= "\n{$unaccountedRows} rows unaccounted for";
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
        $sql = "INSERT INTO dcp2admin.import_results (company_id, filename, created_at) VALUES (:company_id, :filename, NOW())";

        DB::insert($sql, [
            'company_id' => $this->companyId,
            'filename' => basename($this->filename),
        ]);

        $this->fileRowId = DB::getPdo()->lastInsertId();;
    }

    public function updateCompletedRow()
    {
        $sql = "UPDATE dcp2admin.import_results
                SET completed_at = NOW(), adds = :adds, moves = :moves, discos = :discos, skipped = :skipped, metrics = :metrics,
                    skip_list = :skip_list,  errors = :errors, total = :total, skip_invalid_stores = :skip_invalid_stores,
                    skip_invalid_depts = :skip_invalid_depts, skip_invalid_barcodes = :skip_invalid_barcodes,
                    invalid_depts = :invalid_depts, invalid_stores = :invalid_stores, invalid_barcodes = :invalid_barcodes
                    WHERE id = :id";

        DB::update($sql, [
            'id' => $this->fileRowId,
            'adds' => $this->adds,
            'moves' => $this->moves,
            'discos' => $this->discos,
            'skipped' => $this->skipped,
            'skip_list' => $this->skipList,
            'skip_invalid_barcodes' => $this->invalidBarcodeErrors,
            'skip_invalid_depts' => $this->skipDepts,
            'skip_invalid_stores' => $this->skipStores,
            'metrics' => $this->metrics,
            'errors' => $this->errors,
            'total' => $this->total,
            'invalid_depts' => implode(',', $this->invalidDepts),
            'invalid_stores' => implode(',', $this->invalidStores),
            'invalid_barcodes' => implode(',', $this->invalidBarcodes),
        ]);
    }

    public function recordErrorMessage($response)
    {
        if ($response) {
            if ($response->status === 'NOT_VALID') {
                $this->invalidBarcodeErrors++;
            } else {
                if (strpos($response->message, 'Invalid Barcode') !== false) {
                    $this->invalidBarcodeErrors++;
                } else {
                    $this->errors++;
                    $this->insertErrorMessage($response->status, $response->message);
                }
            }
        }
    }

    public function recordError($status, $message)
    {
        $this->errors++;
        $this->insertErrorMessage($status, $message);
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
