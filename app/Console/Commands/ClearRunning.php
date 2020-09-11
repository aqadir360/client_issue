<?php

namespace App\Console\Commands;

use App\Objects\CalculateSchedule;
use App\Objects\Database;
use Illuminate\Console\Command;
use Log;

// Marks any currently in progress jobs as completed
class ClearRunning extends Command
{
    protected $signature = 'dcp:clear';
    protected $description = 'Clears blocks in case of runtime error';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $database = new Database();

        $jobs = $database->fetchIncompleteJobs();
        foreach ($jobs as $job) {
            $database->setImportJobComplete($job->id);
            echo "Clearing Job Id " . $job->id . PHP_EOL;

            $nextRun = CalculateSchedule::calculateNextRun(
                $job->daily,
                $job->week_day,
                $job->month_day,
                $job->start_hour,
                $job->start_minute,
                new \DateTime(),
                $job->archived_at
            );

            if ($nextRun !== null) {
                $database->insertNewJob($job->import_schedule_id, $nextRun);
            }
        }

        $database->cancelRunningImports();
    }
}
