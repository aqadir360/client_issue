<?php

namespace App\Imports;

use App\Objects\Api;
use App\Objects\BarcodeFixer;
use App\Objects\ImportFtpManager;
use App\Objects\ImportStatusOutput;
use Exception;
use Illuminate\Support\Facades\Log;

// Downloads files added to Buehlers FTP in the past week
class ImportBuehlers implements ImportInterface
{
    private $companyId = 'e0700753-8b88-c1b6-8cc9-1613b7154c7e';
    private $path;
    private $departments;
    private $skip = [];

    /** @var ImportStatusOutput */
    private $importStatus;

    /** @var Api */
    private $proxy;

    /** @var ImportFtpManager */
    private $ftpManager;

    public function __construct(Api $api)
    {
        $this->proxy = $api;

        $this->path = storage_path('imports/buehlers/');
        $this->ftpManager = new ImportFtpManager('imports/buehlers/', 'buehler/imports');
        $this->importStatus = new ImportStatusOutput($this->companyId, "Buehlers");
        $this->importStatus->setStores($this->proxy);
        $this->skip = $this->ftpManager->getSkipList();
    }

    public function importUpdates()
    {
        try {
            $discoFiles = [];
            $activeFiles = [];

            $files = $this->ftpManager->getRecentlyModifiedFiles();
            foreach ($files as $file) {
                if (strpos($file, 'Disc') !== false) {
                    $discoFiles[] = $this->ftpManager->downloadFile($file);
                } else {
                    $activeFiles[] = $this->ftpManager->downloadFile($file);
                }
            }

            if (count($discoFiles) > 0 || count($activeFiles) > 0) {
                $this->setDepartments();

                foreach ($discoFiles as $file) {
                    $this->importDiscoFile($file);
                }

                foreach ($activeFiles as $file) {
                    $this->importActiveFile($file);
                }
            }

            $this->proxy->triggerUpdateCounts($this->companyId);
            $this->ftpManager->writeLastDate();
        } catch (Exception $e) {
            $this->importStatus->outputContent($e->getMessage());
            Log::error("Buehler's Import Error", ['error' => $e->getMessage()]);
        }

        $this->importStatus->outputResults();
    }

    private function importDiscoFile($file)
    {
        $this->importStatus->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                $this->importStatus->recordRow();

                $storeId = $this->importStatus->storeNumToStoreId($data[1]);
                if ($storeId === false) {
                    continue;
                }

                $upc = BarcodeFixer::fixUpc(trim($data[2]));
                if ($this->importStatus->isInvalidBarcode($upc)) {
                    continue;
                }

                $product = $this->importStatus->fetchProduct($this->proxy, $upc, $storeId);
                if ($product) {
                    $response = $this->proxy->discontinueProduct($storeId, $product['productId']);
                    $this->importStatus->recordResult($response);
                } else {
                    $this->importStatus->currentFile->skipped++;
                }
            }

            fclose($handle);
        }

        $this->importStatus->completeFile();
    }

    private function importActiveFile($file)
    {
        $this->importStatus->startNewFile($file);

        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, "|")) !== false) {
                $this->importStatus->recordRow();

                $storeId = $this->importStatus->storeNumToStoreId($data[0]);
                if ($storeId === false) {
                    continue;
                }

                $departmentId = $this->deptNameToDeptId($data[2], $data[3]);
                if ($departmentId === false) {
                    $this->importStatus->currentFile->skipped++;
                    continue;
                }

                $upc = $this->fixBarcode($data[1]);
                if ($this->importStatus->isInvalidBarcode($upc)) {
                    continue;
                }

                if (isset($this->skip[intval($upc)])) {
                    $this->importStatus->currentFile->skipped++;
                    continue;
                }

                $product = $this->importStatus->fetchProduct($this->proxy, $upc, $storeId);
                if (null === $product) {
                    continue;
                }

                if ($product !== false && count($product['inventory']) > 0) {
                    $this->importStatus->currentFile->skipped++;
                    continue;
                }

                $response = $this->proxy->implementationScan(
                    $upc,
                    $storeId,
                    'UNKN',
                    '',
                    $departmentId,
                    ucwords(strtolower(trim($data[4]))),
                    strtolower($data[5])
                );
                $this->importStatus->recordResult($response);
                $this->persistMetric($upc, $storeId, $data);
            }

            fclose($handle);
        }

        $this->importStatus->completeFile();
    }

    private function fixBarcode($input)
    {
        return str_pad(ltrim(trim($input), '0'), 13, '0', STR_PAD_LEFT);
    }

    private function persistMetric($upc, $storeId, $row)
    {
        $cost = floatval($row[7]);
        $retail = floatval($row[6]);
        $movement = floatval($row[8]);

        if ($cost != 0 || $retail != 0 || $movement != 0) {
            $this->proxy->persistMetric($upc, $storeId, $cost, $retail, $movement);
        }
    }

    private function setDepartments()
    {
        $response = $this->proxy->fetchDepartments($this->companyId);

        foreach ($response['departments'] as $department) {
            $this->departments[strtolower($department['displayName'])] = $department['departmentId'];
        }
    }

    private function deptNameToDeptId($dept, $category)
    {
        if ($this->shouldSkipDepartment($dept, $category)) {
            return false;
        }

        $deptName = $this->matchDepartment($dept, $category);

        if (isset($this->departments[$deptName])) {
            return $this->departments[$deptName];
        }

        $this->importStatus->addInvalidDepartment($dept . " - " . $category);
        return false;
    }

    private function shouldSkipDepartment($dept, $category): bool
    {
        switch (trim($dept)) {
            case 'GROCERY':
                switch (trim($category)) {
                    case "BREADS/BUNS/ROLLS":
                    case "C'MAS/EASTER CANDY":
                    case "CANDY-CHECKSTAND":
                    case "EXTRACTS/ FLAVORING":
                    case "H'WEEN/VALENTINE CANDY":
                    case "OHIO CIGARS":
                        return true;
                    default:
                        return false;
                }
            case 'MEAT':
                switch (trim($category)) {
                    case "CHICKEN FRESH":
                    case "CHICKEN FRESH/FROZEN":
                    case "FROZEN TURKEY":
                    case "GRINDS":
                        return true;
                    default:
                        return false;
                }
            case 'PRODUCE WIC FVV':
                switch (trim($category)) {
                    case "APPLES":
                    case "BERRIES-PRODUCE":
                    case "BROCCOLI/CALIFLOWER":
                    case "CARROTS-PRODUCE":
                    case "CITRUS FRUITS-PRODUCE":
                    case "CUCUMBERS":
                    case "DELI CHEESES":
                    case "LETTUCE":
                    case "MELONS":
                    case "MISC FRUIT-PRODUCE":
                    case "MUSHROOMS-PRODUCE":
                    case "ONIONS-PRODUCE":
                    case "ORGANIC PRODUCE":
                    case "PEARS,PEACHS,NECTARINES":
                    case "PEPPERS-PRODUCE":
                    case "PINEAPPLES":
                    case "PRODUCE DEPT":
                        return true;
                    default:
                        return false;
                }
            case 'PRODUCE-NON WIC FVV':
                switch (trim($category)) {
                    case "BAG SALAD MIXES":
                    case "CANDY-PRODUCE":
                    case "GARLIC-PRODUCE":
                    case "MISC PRODUCE":
                    case "MISC VEG-PRODUCE":
                    case "POTATOES-PRODUCE":
                    case "PRODUCE JUICE":
                    case "PRODUCE SNACKS":
                        return true;
                    default:
                        return false;
                }
            case 'TAXABLE GROCERY':
                switch (trim($category)) {
                    case "ALUMINUM/WAX FOOD WRAP":
                    case "BAGS SANDWICH/TRASH/UTIL":
                    case "BLEACH/PREWASH":
                    case "CAT LITTER":
                    case "CHARCOAL/ LIGHTER/LOGS":
                    case "CLEANER BATHROOM/TOILET":
                    case "CLEANER RUG/UPHLSTR":
                    case "CLEANER WINDOW":
                    case "CLEANERS":
                    case "DEODORIZERS":
                    case "FABRIC CARE PRODUCTS":
                    case "FABRIC SOFTENERS":
                    case "INSECT PRODUCTS":
                    case "PAPER TOWELS/HOLDERS":
                    case "PLATES/CUPS/UTENSILS":
                    case "SOAP DISHWASHER":
                    case "SOAP/DET LIQUID DISH":
                    case "SOAP/DET LIQUID LAUNDRY":
                    case "TISSUE FACIAL":
                    case "TISSUE TOILET":
                        return true;
                    default:
                        return false;
                }
            case 'TAXABLE PRODUCE':
                switch (trim($category)) {
                    case "PLANTS/OUTSIDE":
                        return true;
                    default:
                        return false;
                }
            case 'TX-FD-STAMP GROCERY':
                switch (trim($category)) {
                    case "7-UP AKRON":
                    case "WATER":
                        return true;
                    default:
                        return false;
                }
        }

        return false;
    }

    private function matchDepartment($dept, $category)
    {
        switch (strtolower(trim($dept))) {
            case 'dairy':
                return $this->matchDairyDepartment($category);
            case '':
            case 'grocery':
            case 'grocery wic fvv':
            case 'taxable grocery':
            case 'tx-fd-stamp grocery':
                return $this->matchGroceryDepartment($category);
            case 'meat':
                return 'meat';
            case 'produce':
            case 'produce wic fvv':
            case 'produce-non wic fvv':
            case 'taxable produce':
                return 'produce';
        }

        return false;
    }

    private function matchGroceryDepartment($category)
    {
        if (strpos(strtolower($category), 'baby') !== false) {
            return 'grocery - baby food';
        }

        return 'grocery';
    }

    private function matchDairyDepartment($category)
    {
        switch ($category) {
            case 'FLUID SOY MILK':
            case 'LACTOSE FREE MILK':
                return 'dairy';
        }

        if (strpos(strtolower($category), 'yogurt') !== false) {
            return 'dairy - yogurt';
        }

        if (strpos(strtolower($category), 'milk') !== false) {
            return 'dairy - short life';
        }

        return 'dairy';
    }
}
