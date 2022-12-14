<?php

namespace App\Imports;

use App\Objects\ImportManager;

class ImportFactory
{
    public static function createImport(string $key, ImportManager $importManager): ?ImportInterface
    {
        switch ($key) {
            case 'alaska':
                return new ImportAlaska($importManager);
            case 'b_green':
                return new ImportBGreen($importManager);
            case 'bristol_metrics':
                return new ImportBristolMetrics($importManager);
            case 'buehlers':
                return new ImportBuehlers($importManager);
            case 'caputos':
                return new ImportCaputos($importManager);
            case 'cub':
                return new ImportCubFoods($importManager);
            case 'cub_inventory':
                return new Refresh\CubFoodsInventory($importManager);
            case 'downtoearth':
                return new ImportDownToEarth($importManager);
            case 'foxbros':
            case 'mayville_compare': // Mayville shares files with Fox Bros
                return new ImportFoxBros($importManager);
            case 'hansens':
                return new ImportHansens($importManager);
            case 'hansens_metrics':
                return new ImportHansensMetrics($importManager);
            case 'hardings':
                return new ImportHardings($importManager);
            case 'janssens':
                return new ImportJanssens($importManager);
            case 'karns':
                return new ImportKarns($importManager);
            case 'lazy_acres':
                return new ImportLazyAcres($importManager);
            case 'leevers_metrics':
                return new ImportLeeversMetrics($importManager);
            case 'leprekon':
                return new ImportLePreKon($importManager);
            case 'lunds':
                return new ImportLunds($importManager);
            case 'lunds_metrics':
                return new ImportLundsMetrics($importManager);
            case 'mayville':
                return new ImportMayville($importManager);
            case 'metcalfes_metrics':
                return new ImportMetcalfesMetrics($importManager);
            case 'new_morning_market':
                return new ImportNewMorningMarket($importManager);
            case 'price_chopper':
                return new ImportPriceChopper($importManager);
            case 'price_chopper_compare':
                return new Refresh\PriceChopperInventory($importManager);
            case 'raleys':
                return new ImportRaleys($importManager);
            case 'raleys_refresh':
                return new Refresh\RaleysInventory($importManager);
            case 'raleys_metrics':
                return new ImportRaleysMetrics($importManager);
            case 'seg':
                return new ImportSEGUpdates($importManager);
            case 'seg_users':
                return new ImportSEGUserUpdates($importManager);
            case 'sprouts':
                return new ImportSprouts($importManager);
            case 'vallarta':
                return new ImportVallarta($importManager);
            case 'vallarta_baby':
                return new Refresh\VallartaBaby($importManager);
            case 'vallarta_refresh':
                return new Refresh\VallartaInventory($importManager);
            case 'websters':
                return new ImportWebsters($importManager);
            case 'websters_metrics':
                return new ImportWebstersMetrics($importManager);
            case 'bristol_farms_compare':
                return new Refresh\BristolFarmInventory($importManager);
            case 'sprouts_compare':
                return new Refresh\SproutsInventory($importManager);
            case 'alaska_compare':
                return new Refresh\AlaskaInventory($importManager);
            case 'maurers_compare':
                return new Refresh\MaurersInventory($importManager);
            default:
                echo "Invalid Input $key" . PHP_EOL;
                return null;
        }
    }
}
