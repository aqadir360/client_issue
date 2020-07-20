<?php

namespace App\Console\Commands;

use App\Imports\ImportBuehlers;
use App\Imports\ImportDownToEarth;
use App\Imports\ImportHansens;
use App\Imports\ImportHansensMetrics;
use App\Imports\ImportInterface;
use App\Imports\ImportRaleys;
use App\Imports\ImportRaleysMetrics;
use App\Imports\ImportSEG;
use App\Imports\ImportVallarta;
use App\Imports\ImportWebsters;
use App\Imports\ImportWebstersMetrics;
use App\Objects\Api;
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

        $import = $this->getImport($company, new Api());

        if ($import !== null) {
            try {
                $import->importUpdates();
            } catch (Exception $e) {
                var_dump($e);
            }
        }
    }

    private function getImport(?string $company, Api $api): ?ImportInterface
    {
        switch ($company) {
            case 'buehlers':
                return new ImportBuehlers($api);
            case 'downtoearth':
                return new ImportDownToEarth($api);
            case 'hansens':
                return new ImportHansens($api);
            case 'hansens_metrics':
                return new ImportHansensMetrics($api);
            case 'raleys':
                return new ImportRaleys($api);
            case 'raleys_metrics':
                return new ImportRaleysMetrics($api);
            case 'seg':
                return new ImportSEG($api);
            case 'vallarta':
                return new ImportVallarta($api);
            case 'websters':
                return new ImportWebsters($api);
            case 'websters_metrics':
                return new ImportWebstersMetrics($api);
            default:
                echo "Invalid Input $company" . PHP_EOL;
                return null;
        }
    }
}
