<?php

namespace App\Console\Commands;

use App\Objects\Database;
use Illuminate\Console\Command;
use Log;

// Checks for the next pending items and runs
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
        }

        $database->cancelRunningImports();
    }
}
