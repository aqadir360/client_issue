<?php

namespace App\Imports\Overlay;

use App\Imports\Overlay\Settings\OosMapper as Settings;
use App\Objects\Api;
use App\Objects\Database;
use DateTime;
use Log;

// Overlays dates for all OOS items with closest non-expired date within company
class OverlayOos
{
    /** @var Api */
    private $proxy;

    /** @var Database */
    public $db;

    protected $key = 'overlay_oos';

    public function __construct(Api $api, Database $db)
    {
        $this->proxy = $api;
        $this->db = $db;
    }

    public function importUpdates(string $dbName, string $importTypeId, string $companyId, int $scheduleId, int $jobId)
    {
        $this->db->setDbName($dbName);
        $importStatusId = $this->db->startImport($importTypeId, $jobId);
        $resultId = $this->db->insertResultsRow($importStatusId, "OOS Overlay");
        $settings = $this->getImportSettings($scheduleId);

        try {
            $this->overlayInventory($companyId, $settings, $resultId);
            $this->proxy->triggerUpdateCounts($companyId);
            $this->db->completeImport($importStatusId, 1, 0, '');
        } catch (\Exception $e) {
            $this->db->updateOverlayResultsRow($resultId, 0, 0, 0, $e->getMessage());
            $this->db->completeImport($importStatusId, 1, 0, $e->getMessage());
            echo $e->getMessage() . PHP_EOL;
            Log::error($e);
        }
    }

    private function overlayInventory(string $companyId, Settings $settings, $resultId)
    {
        $inventory = $this->db->getOosInventory($companyId, $settings->excludeStores, $settings->excludeDepts);

        $total = count($inventory);
        $inventoryCount = 0;
        $skipped = 0;

        foreach ($inventory as $item) {
            $date = null;

            if ($settings->expirationDate == 'date_range') {
                $startUnix = strtotime($settings->startDate);
                $endUnix = strtotime($settings->endDate);

                $date = new DateTime();
                $date->setTimestamp($startUnix + ($endUnix - $startUnix) * ($inventoryCount / $total));
                $date->setTime(0, 0, 0);
            } else {
                $date = $this->getExpirationDate($item, $settings, $companyId);

                if ($date === null) {
                    $skipped++;
                    continue;
                }
            }

            $this->proxy->writeInventoryExpiration($companyId, $item->inventory_item_id, $date);
            $inventoryCount++;
        }

        $finalCount = $this->db->getOosInventoryCount($companyId, $settings->excludeStores, $settings->excludeDepts);

        $output = "initial: $total, final: $finalCount";

        $this->db->updateOverlayResultsRow($resultId, $total, $inventoryCount, $skipped, $output);
    }

    private function getImportSettings($scheduleId): Settings
    {
        $result = $this->db->fetchCustomImportSettings($scheduleId);
        return new Settings($result);
    }

    private function getExpirationDate($item, Settings $settings, $companyId): ?string
    {
        $direction = $settings->expirationDate == 'closest_date' ? 'asc' : 'desc';
        $closestDate = $this->db->fetchClosestDate($item->product_id, $companyId, $settings->copyFrom, $direction, $settings->maxDate);

        if ($closestDate && $closestDate->expiration_date) {
            return $closestDate->expiration_date;
        }

        return null;
    }
}
