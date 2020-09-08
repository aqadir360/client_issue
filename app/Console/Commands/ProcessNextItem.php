<?php

namespace App\Console\Commands;

use App\Imports\ImportFactory;
use App\Objects\Api;
use App\Objects\CalculateSchedule;
use App\Objects\Database;
use App\Objects\ImportManager;
use Exception;
use Illuminate\Console\Command;
use Log;

// Checks for the next pending items and runs
class ProcessNextItem extends Command
{
    protected $signature = 'dcp:process-import';
    protected $description = 'Starts next available import';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $database = new Database();

        $active = $database->fetchCurrentImport();
        if ($active) {
            Log::info("Import already in progress");
            return;
        }

        $now = new \DateTime();
        $now->setTimezone(new \DateTimeZone('UTC'));

        $pending = $database->fetchNextUpcomingImport($now->format('Y-m-d H:i:s'));
        if ($pending === null) {
            Log::info("No pending imports");
            return;
        }

        Log::info("Starting import");

        $lastRun = $database->fetchLastRun($pending->import_type_id);

        $importManager = new ImportManager(
            new Api(),
            $database,
            $pending->company_id,
            $pending->ftp_path,
            intval($pending->import_type_id),
            $lastRun,
            config('scraper.debug_mode') === 'debug'
        );

        $database->setImportJobInProgess($pending->import_job_id);
        $import = ImportFactory::createImport($pending->type, $importManager);

        if ($import !== null) {
            try {
                $import->importUpdates();
            } catch (Exception $e) {
                $importManager->completeImport($e->getMessage());
                echo $e->getMessage() . PHP_EOL;
                Log::error($e);
            }
        }

        $database->setImportJobComplete($pending->import_job_id);

        $nextRun = CalculateSchedule::calculateNextRun(
            $pending->daily,
            $pending->week_day,
            $pending->month_day,
            $pending->start_hour,
            $pending->start_minute,
            $pending->archived_at
        );

        if ($nextRun !== null) {
            $database->insertNewJob($pending->import_schedule_id, $nextRun);
        }
    }
}
