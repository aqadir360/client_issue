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
        $calculator = new CalculateSchedule($database);

        $jobs = $database->fetchIncompleteJobs();
        foreach ($jobs as $job) {
            $database->setImportJobComplete($job->id);
            echo "Clearing Job Id " . $job->id . PHP_EOL;

            $calculator->calculateNextRun(
                $job->import_schedule_id,
                $job->daily,
                $job->week_day,
                $job->month_day,
                $job->start_hour,
                $job->start_minute,
                $job->archived_at
            );
        }

        $database->cancelRunningImports();
    }
}
