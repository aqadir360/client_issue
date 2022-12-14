<?php

namespace App\Objects;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FileStatus
{
    private $fileRowId;
    private $content = '';
    public $filename;

    public $adds = 0;
    public $moves = 0;
    public $discos = 0;
    public $metrics = 0;
    public $newproducts = 0;
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

    public function __construct($filename)
    {
        $this->filename = $filename;
    }

    public function outputContent($content)
    {
        $this->content .= "<p>" . $content . "</p>";
    }

    public function getFileRowId()
    {
        return $this->fileRowId;
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

        if ($this->newproducts > 0) {
            $str .= ", {$this->newproducts} products created";
        }

        if ($this->metrics > 0) {
            $str .= ", {$this->metrics} metrics";
            $unaccountedRows -= $this->metrics;
        }

        if ($this->errors > 0) {
            $str .= ", {$this->errors} errors";
            $unaccountedRows -= $this->errors;
        }

        if ($this->static > 0) {
            $str .= ", {$this->static} unchanged";
            $unaccountedRows -= $this->static;
        }

        if ($this->skipped > 0) {
            $str .= ", {$this->skipped} skipped";
            $unaccountedRows -= $this->skipped;
        }

        if ($this->skipList > 0) {
            $str .= ", {$this->skipList} skip list items skipped";
            $unaccountedRows -= $this->skipList;
        }

        if ($this->skipStores > 0) {
            $str .= ", {$this->skipStores} skipped stores";
            $unaccountedRows -= $this->skipStores;
        }

        if ($this->skipDepts > 0) {
            $str .= ", {$this->skipDepts} skipped departments";
            $unaccountedRows -= $this->skipDepts;
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

    public function resetCounts()
    {
        $this->adds = 0;
        $this->moves = 0;
        $this->discos = 0;
        $this->metrics = 0;
        $this->newproducts = 0;
        $this->errors = 0;
        $this->total = 0;
        $this->static = 0;
        $this->skipped = 0;
        $this->skipList = 0;
        $this->skipStores = 0;
        $this->skipDepts = 0;
        $this->invalidBarcodeErrors = 0;
        $this->invalidDepts = [];
        $this->invalidStores = [];
        $this->invalidBarcodes = [];
    }

    public function deleteFile()
    {
        if (file_exists($this->filename)) {
            unlink($this->filename);
        }
    }

    public function insertFileRow($importStatusId, $outputFileName)
    {
        $sql = "INSERT INTO import_results (import_status_id, filename, output_file, created_at)
            VALUES (:import_status_id, :filename, :output_file, NOW())";

        DB::insert($sql, [
            'import_status_id' => $importStatusId,
            'filename' => basename($this->filename),
            'output_file' => $outputFileName,
        ]);

        $this->fileRowId = DB::getPdo()->lastInsertId();
    }

    public function updateCompletedRow()
    {
        $sql = "UPDATE import_results SET completed_at = NOW(), adds = :adds, moves = :moves, discos = :discos,
            skipped = :skipped, metrics = :metrics, errors = :errors, total = :total, new_products = :new_products,
            output = :output, skip_invalid_stores = :skip_invalid_stores, skip_list = :skip_list, static = :static,
            skip_invalid_barcodes = :skip_invalid_barcodes, skip_invalid_depts = :skip_invalid_depts,
            invalid_barcodes = :invalid_barcodes, invalid_depts = :invalid_depts, invalid_stores = :invalid_stores
            WHERE id = :id";

        DB::update($sql, [
            'id' => $this->fileRowId,
            'adds' => $this->adds,
            'moves' => $this->moves,
            'discos' => $this->discos,
            'new_products' => $this->newproducts,
            'static' => $this->static,
            'skipped' => $this->skipped,
            'skip_list' => $this->skipList,
            'skip_invalid_barcodes' => $this->invalidBarcodeErrors,
            'skip_invalid_depts' => $this->skipDepts,
            'skip_invalid_stores' => $this->skipStores,
            'metrics' => $this->metrics,
            'errors' => $this->errors,
            'output' => $this->content,
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
                    return;
                }

                if (strpos($response->message, 'Product Not Found') !== false) {
                    $this->skipped++;
                    return;
                }

                $this->errors++;
                $this->logErrorMessage($response->status, $response->message);
            }
        }
    }

    public function recordError($status, $message)
    {
        $this->errors++;
        $this->logErrorMessage($status, $message);
    }

    private function logErrorMessage(string $status, string $message)
    {
        Log::error($message, ['importId' => $this->fileRowId, 'status' => $status, 'row' => $this->total]);
    }
}
