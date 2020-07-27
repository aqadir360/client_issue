<?php

namespace App\Console\Commands;

use App\Imports\ImportBuehlers;
use App\Imports\ImportDownToEarth;
use App\Imports\ImportHansens;
use App\Imports\ImportHansensMetrics;
use App\Imports\ImportInterface;
use App\Imports\ImportLunds;
use App\Imports\ImportRaleys;
use App\Imports\ImportRaleysMetrics;
use App\Imports\ImportSEG;
use App\Imports\ImportVallarta;
use App\Imports\ImportWebsters;
use App\Imports\ImportWebstersMetrics;
use App\Objects\Api;
use App\Objects\Database;
use Exception;
use Illuminate\Console\Command;

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
        $company = $this->argument('company');

        $database = new Database();
        $import = $this->getImport($company, new Api(), $database);

        if ($import !== null) {
            try {
                $import->importUpdates();
            } catch (Exception $e) {
                var_dump($e);
            }
        }
    }

    private function getImport(?string $company, Api $api, Database $database): ?ImportInterface
    {
        switch ($company) {
            case 'buehlers':
                return new ImportBuehlers($api, $database);
            case 'downtoearth':
                return new ImportDownToEarth($api, $database);
            case 'hansens':
                return new ImportHansens($api, $database);
            case 'hansens_metrics':
                return new ImportHansensMetrics($api, $database);
            case 'lunds':
                return new ImportLunds($api, $database);
            case 'raleys':
                return new ImportRaleys($api, $database);
            case 'raleys_metrics':
                return new ImportRaleysMetrics($api, $database);
            case 'seg':
                return new ImportSEG($api, $database);
            case 'vallarta':
                return new ImportVallarta($api, $database);
            case 'websters':
                return new ImportWebsters($api, $database);
            case 'websters_metrics':
                return new ImportWebstersMetrics($api, $database);
            default:
                echo "Invalid Input $company" . PHP_EOL;
                return null;
        }
    }
}
