<?php

namespace App\Imports;

use App\Imports\Refresh\RaleysInventory;
use App\Imports\Refresh\VallartaBaby;
use App\Imports\Refresh\VallartaInventory;
use App\Objects\ImportManager;

class ImportFactory
{
    public static function createImport(string $key, ImportManager $importManager): ?ImportInterface
    {
        switch ($key) {
            case 'alaska':
                return new ImportAlaska($importManager);
            case 'bristol_metrics':
                return new ImportBristolMetrics($importManager);
            case 'buehlers':
                return new ImportBuehlers($importManager);
            case 'caputos':
                return new ImportCaputos($importManager);
            case 'downtoearth':
                return new ImportDownToEarth($importManager);
            case 'foxbros':
                return new ImportFoxBros($importManager);
            case 'hansens':
                return new ImportHansens($importManager);
            case 'hansens_metrics':
                return new ImportHansensMetrics($importManager);
            case 'hardings':
                return new ImportHardings($importManager);
            case 'janssens':
                return new ImportJanssens($importManager);
            case 'leprekon':
                return new ImportLePreKon($importManager);
            case 'lunds':
                return new ImportLunds($importManager);
            case 'lunds_metrics':
                return new ImportLundsMetrics($importManager);
            case 'metcalfes_metrics':
                return new ImportMetcalfesMetrics($importManager);
            case 'raleys':
                return new ImportRaleys($importManager);
            case 'raleys_refresh':
                return new RaleysInventory($importManager);
            case 'raleys_metrics':
                return new ImportRaleysMetrics($importManager);
            case 'seg':
                return new ImportSEG($importManager);
            case 'seg_users':
                return new ImportSEGUsers($importManager);
            case 'vallarta':
                return new ImportVallarta($importManager);
            case 'vallarta_baby':
                return new VallartaBaby($importManager);
            case 'vallarta_refresh':
                return new VallartaInventory($importManager);
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
