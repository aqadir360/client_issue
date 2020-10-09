<?php

namespace App\Imports;

use App\Objects\ImportManager;

class ImportFactory
{
    public static function createImport(string $key, ImportManager $importManager): ?ImportInterface
    {
        switch ($key) {
            case 'buehlers':
                return new ImportBuehlers($importManager);
            case 'downtoearth':
                return new ImportDownToEarth($importManager);
            case 'foxbros':
                return new ImportFoxBros($importManager);
            case 'hansens':
                return new ImportHansens($importManager);
            case 'hansens_metrics':
                return new ImportHansensMetrics($importManager);
            case 'leprekon':
                return new ImportLePreKon($importManager);
            case 'lunds':
                return new ImportLunds($importManager);
            case 'raleys':
                return new ImportRaleys($importManager);
            case 'raleys_metrics':
                return new ImportRaleysMetrics($importManager);
            case 'seg':
                return new ImportSEG($importManager);
            case 'vallarta':
                return new ImportVallarta($importManager);
            case 'websters':
                return new ImportWebsters($importManager);
            case 'websters_metrics':
                return new ImportWebstersMetrics($importManager);
            default:
                echo "Invalid Input $key" . PHP_EOL;
                return null;
        }
    }
}
