<?php

namespace App\Imports;

use App\Models\OverlayOOSSettings;
use App\Objects\Api;
use App\Objects\Database;

// Overlays dates for all new items with closest non-expired date within company
class OverlayOOS
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

    public function importUpdates(string $companyId, int $scheduleId)
    {
        $today = new \DateTime();

        $importStatusId =  $this->db->startImport($scheduleId);
        $resultId = $this->db->insertResultsRow($importStatusId, "OOS Overlay");
        $settings = $this->getImportSettings($companyId);

        $inventory = $this->db->getOosInventory($companyId, $settings->excludeStores, $settings->excludeDepts);

        $total = count($inventory);
        $inventoryCount = 0;

        foreach ($inventory as $item) {
            $date = null;

            if ($settings->expirationDate == 'date_range') {
                $startUnix = strtotime($settings->startDate);
                $endUnix = strtoTime($settings->endDate);

                $date = new \DateTime();
                $date->setTimestamp($startUnix + ($endUnix - $startUnix) * ($inventoryCount / $total));
                $date->setTime(0, 0, 0);
            } else {
                $date = $this->getExpirationDate($item, $settings->expirationDate, $companyId, $item->store_id, $today);
            }

            $this->proxy->writeInventoryExpiration($item->inventory_item_id, $date);
            $inventoryCount++;
        }

        $finalCount = $this->db->getOosInventoryCount($companyId, $settings->excludeStores, $settings->excludeDepts);

        $output = "initial: " . count($inventory) . ", final: $finalCount";

        $this->db->updateOverlayResultsRow($resultId, $total, $inventoryCount, 0, $output);

        $this->db->completeImport($importStatusId, 1, 0, '');
    }

    private function getImportSettings(string $companyId): OverlayOOSSettings
    {
        $result = $this->db->fetchCustomImportSettings($this->key, $companyId);
        return new OverlayOOSSettings($result);
    }

    private function getExpirationDate($item, $expirationDate, $storeId, $companyId, \DateTime $today)
    {
        $closeDate = new \DateTime($item->close_dated_date);
        $compareDate = $closeDate < $today ? $closeDate->format('Y-m-d') : $today->format('Y-m-d');

        if ($expirationDate == 'closest_date') {
            return $this->db->fetchOOSClosestDate($item->product_id, $storeId, $companyId, $compareDate);
        } else {
            return $this->db->fetchOOSFurthestDate($item->product_id, $storeId, $companyId, $compareDate);
        }
    }
}
