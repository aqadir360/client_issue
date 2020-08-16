<?php

namespace App\Console\Commands;

use App\Imports\ImportFactory;
use App\Objects\Api;
use App\Objects\Database;
use App\Objects\ImportManager;
use Exception;
use Illuminate\Console\Command;
use Log;

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
        if ($import === false) {
            echo "Invalid Input $key" . PHP_EOL;
            return;
        }

        $importManager = new ImportManager(
            new Api(),
            $database,
            $import->company_id,
            $import->ftp_path,
            intval($import->id),
            intval($import->compare_date),
            config('scraper.debug_mode') === 'debug'
        );

        $import = ImportFactory::createImport($key, $importManager);

        if ($import !== null) {
            try {
                $import->importUpdates();
            } catch (Exception $e) {
                $importManager->completeImport($e->getMessage());
                echo $e->getMessage() . PHP_EOL;
                Log::error($e);
            }
        }
    }
}
