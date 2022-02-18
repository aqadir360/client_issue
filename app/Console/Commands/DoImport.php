<?php

namespace App\Console\Commands;

use App\Imports\ImportFactory;
use App\Objects\Api;
use App\Objects\Database;
use App\Objects\FtpManager;
use App\Objects\ImportManager;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

// Manually runs the input import
class DoImport extends Command
{
    protected $signature = 'dcp:do-import {company}';
    protected $description = 'Imports company files';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $key = $this->argument('company');
        if (empty($key)) {
            echo "Missing import key" . PHP_EOL;
            return;
        }

        $database = new Database();
        $import = $database->fetchImportByType($key);
        if ($import === null) {
            echo "Invalid Input $key" . PHP_EOL;
            return;
        }

        $lastRun = $database->fetchLastRun($import->type_id);

        $importManager = new ImportManager(
            new Api(),
            $database,
            new FtpManager($import->ftp_path, $lastRun),
            $import->company_id,
            $import->db_name,
            intval($import->type_id),
            null,
            config('scraper.debug_mode') === 'debug'
        );

        $import = ImportFactory::createImport($key, $importManager);

        if ($import !== null) {
            try {
                $import->importUpdates();
            } catch (Exception $e) {
                var_dump($e);
                $importManager->completeImport($e->getMessage());
                Log::error($e->getTraceAsString());
            }
        }
    }
}
