<?php

namespace App\Console\Commands;

use App\Objects\Api;
use App\Objects\Database;
use Illuminate\Console\Command;
use Log;

class CopyMetrics extends Command
{
    protected $signature = 'dcp:copy-metrics';
    protected $description = 'Copies update metrics values';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $database = new Database();
        $companies = $database->fetchImportCompanies();

        $api = new Api();

        foreach ($companies as $company) {
            $start = microtime(true);
            $api->copyMetrics($company->company_id);
            $end = microtime(true);
            Log::error("Copied Metrics " . $company->company_id . " " . ($end - $start));
        }
    }
}
