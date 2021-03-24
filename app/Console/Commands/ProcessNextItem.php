<?php

namespace App\Console\Commands;

use App\Imports\ImportFactory;
use App\Imports\Overlay\OverlayNew;
use App\Imports\Overlay\OverlayOos;
use App\Objects\Api;
use App\Objects\CalculateSchedule;
use App\Objects\Database;
use App\Objects\FtpManager;
use App\Objects\ImportManager;
use DateTime;
use DateTimeZone;
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
            echo "Import already in progress\n";
            return;
        }

        $now = new DateTime();
        $now->setTimezone(new DateTimeZone('UTC'));

        $pending = $database->fetchNextUpcomingImport($now->format('Y-m-d H:i:s'));

        if ($pending === null) {
            echo "No pending imports\n";
            return;
        }

        $database->setImportJobInProgress($pending->import_job_id);

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
        $lastRun = $database->fetchLastRun($pending->import_type_id);

        $importManager = new ImportManager(
            new Api(),
            $database,
            new FtpManager($pending->ftp_path, $lastRun),
            $pending->company_id,
            $pending->db_name,
            intval($pending->import_type_id),
            intval($pending->import_job_id),
            config('scraper.debug_mode') === 'debug'
        );

        $import = ImportFactory::createImport($pending->type, $importManager);

        if ($import !== null) {
            try {
                $import->importUpdates();
            } catch (Exception $e) {
                $importManager->completeImport($e->getMessage());
                echo $e->getMessage() . PHP_EOL;
                Log::error($e->getTraceAsString());
            }
        }
    }

    private function runOverlay(Database $database, $pending)
    {
        switch ($pending->type) {
            case 'overlay_new':
                $import = new OverlayNew(new Api(), $database);
                break;
            case 'overlay_oos':
                $import = new OverlayOos(new Api(), $database);
                break;
            default:
                echo "Invalid Overlay Type " . $pending->type;
                return;
        }

        $import->importUpdates($pending->db_name, $pending->import_type_id, $pending->company_id, $pending->import_schedule_id);
    }
}
