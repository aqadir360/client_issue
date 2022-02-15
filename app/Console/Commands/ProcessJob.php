<?php

namespace App\Console\Commands;

use App\Imports\ImportFactory;
use App\Imports\Overlay\OverlayNew;
use App\Imports\Overlay\OverlayNotifications;
use App\Imports\Overlay\OverlayOos;
use App\Objects\Api;
use App\Objects\CalculateSchedule;
use App\Objects\Database;
use App\Objects\FtpManager;
use App\Objects\ImportManager;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

// Checks for the next pending items and runs
class ProcessJob extends Command
{
    protected $signature = 'dcp:process-job {job_id}';
    protected $description = 'Starts selected job';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $database = new Database();

        $jobId = $this->argument('job_id');
        if (empty($jobId)) {
            echo "Missing job id" . PHP_EOL;
            return;
        }

        $pending = $database->fetchImportByJobId($jobId);

        if ($pending === null) {
            $this->log("No pending imports");
            return;
        }

        $database->setImportJobInProgress($pending->import_job_id);

        if (strpos($pending->type, 'overlay') !== false) {
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
                $this->log($e->getMessage());
                $importManager->completeImport($e->getMessage());
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
            case 'overlay_notifications':
                $import = new OverlayNotifications(new Api(), $database);
                break;
            default:
                $this->log("Invalid Overlay Type " . $pending->type);
                return;
        }

        $import->importUpdates(
            $pending->db_name,
            $pending->import_type_id,
            $pending->company_id,
            $pending->import_schedule_id,
            intval($pending->import_job_id)
        );
    }

    private function log($output)
    {
        Log::error($output);
        echo $output . PHP_EOL;
    }
}
