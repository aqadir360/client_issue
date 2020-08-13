<?php

namespace App\Console\Commands;

use App\Imports\ImportFactory;
use App\Objects\Api;
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
            Log::error("Import already in progress");
            return;
        }

        $now = new \DateTime();
        $now->setTimezone(new \DateTimeZone('UTC'));

        $pending = $database->fetchNextUpcomingImport($now->format('Y-m-d H:i:s'));
        if ($pending === null) {
            Log::error("No pending imports");
            return;
        }

        Log::error("Starting import");

        $importManager = new ImportManager(
            new Api(),
            $database,
            $pending->company_id,
            $pending->ftp_path,
            intval($pending->import_type_id),
            intval($pending->last_run),
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
        $this->calculateNextRun(
            $database,
            $pending->import_schedule_id,
            $pending->daily,
            $pending->week_day,
            $pending->month_day,
            $pending->start_hour,
            $pending->start_minute
        );
    }

    private function calculateNextRun(
        Database $database,
        $importScheduleId,
        $daily,
        $weekDay,
        $monthDay,
        $startHour,
        $startMinute
    ) {
        $date = new \DateTime();

        if (intval($daily) === 1) {
            $date->add(new \DateInterval('P1D'));
        } else {
            if ($weekDay !== null) {
                $date->modify('next ' . $this->getWeekDay($weekDay));
            } elseif ($monthDay !== null) {
                $date->setDate($date->format('Y'), (intval($date->format('m')) + 1), $monthDay);
            }
        }

        $date->setTimezone(new \DateTimeZone('CST'));
        $date->setTime($startHour, $startMinute);

        $date->setTimezone(new \DateTimeZone('UTC'));
        $database->insertNewJob($importScheduleId, $date->format('Y-m-d H:i:s'));
    }

    private function getWeekDay(int $day)
    {
        switch ($day) {
            case 0:
                return 'monday';
            case 1:
                return 'tuesday';
            case 2:
                return 'wednesday';
            case 3:
                return 'thursday';
            case 4:
                return 'friday';
            case 5:
                return 'saturday';
            case 6:
                return 'sunday';
        }
    }
}
