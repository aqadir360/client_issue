<?php

namespace App\Console\Commands;

use App\Imports\ImportFactory;
use App\Imports\OverlayNewItems;
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

        $database->setImportJobInProgess($pending->import_job_id);

        echo "Starting " . $pending->type . PHP_EOL;

        if ($pending->type === 'overlay_new' || $pending->type === 'overlay_oos') {
            $this->runOverlay($database, $pending);
        } else {
            $this->runFileImport($database, $pending);
        }

        $database->setImportJobComplete($pending->import_job_id);
        CalculateSchedule::createNextRun($database, $pending->import_schedule_id);
    }

    private function runFileImport(Database $database, $pending)
    {
        $lastRun = $database->fetchLastRun($pending->import_schedule_id);

        $importManager = new ImportManager(
            new Api(),
            $database,
            $pending->company_id,
            $pending->ftp_path,
            intval($pending->import_type_id),
            intval($pending->import_schedule_id),
            $lastRun,
            config('scraper.debug_mode') === 'debug'
        );

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
    }

    private function runOverlay(Database $database, $pending)
    {
        if ($pending->type == 'overlay_new') {
            $import = new OverlayNewItems(new Api(), $database);
        }

        if ($pending->type == 'overlay_oos') {
            $import = new OverlayOOS(new Api(), $database);
        }

        $import->importUpdates($pending->company_id, $pending->import_schedule_id);
    }
}
