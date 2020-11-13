<?php

namespace App\Imports;

use App\Models\OverlayOOSSettings;
use App\Objects\Api;
use App\Objects\Database;

// Overlays dates for all OOS items with closest non-expired date within company
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
        $importStatusId = $this->db->startImport($scheduleId);
        $resultId = $this->db->insertResultsRow($importStatusId, "OOS Overlay");
        $settings = $this->getImportSettings($companyId);

        try {
            $this->overlayInventory($companyId, $settings, $resultId);
            $this->db->completeImport($importStatusId, 1, 0, '');
        } catch (\Exception $e) {
            $this->db->updateOverlayResultsRow($resultId, 0, 0, 0, $e->getMessage());
            $this->db->completeImport($importStatusId, 1, 0, $e->getMessage());
            echo $e->getMessage() . PHP_EOL;
            Log::error($e);
        }
    }

    private function overlayInventory(string $companyId, OverlayOOSSettings $settings, $resultId)
    {
        $today = new \DateTime();

        $inventory = $this->db->getOosInventory($companyId, $settings->excludeStores, $settings->excludeDepts);

        $total = count($inventory);
        $inventoryCount = 0;
        $skipped = 0;

        foreach ($inventory as $item) {
            $date = null;

            if ($settings->expirationDate == 'date_range') {
                $startUnix = strtotime($settings->startDate);
                $endUnix = strtotime($settings->endDate);

                $date = new \DateTime();
                $date->setTimestamp($startUnix + ($endUnix - $startUnix) * ($inventoryCount / $total));
                $date->setTime(0, 0, 0);
            } else {
                $date = $this->getExpirationDate($item, $settings, $companyId, $item->store_id, $today);

                if ($date === null) {
                    $skipped++;
                    continue;
                }
            }

            $this->proxy->writeInventoryExpiration($item->inventory_item_id, $date->format('Y-m-d'));
            $inventoryCount++;
        }

        $finalCount = $this->db->getOosInventoryCount($companyId, $settings->excludeStores, $settings->excludeDepts);

        $output = "initial: $total, final: $finalCount";

        $this->db->updateOverlayResultsRow($resultId, $total, $inventoryCount, $skipped, $output);
    }

    private function getImportSettings(string $companyId): OverlayOOSSettings
    {
        $result = $this->db->fetchCustomImportSettings($this->key, $companyId);
        return new OverlayOOSSettings($result);
    }

    private function getExpirationDate($item, OverlayOOSSettings $settings, $storeId, $companyId, \DateTime $today)
    {
        $closeDate = new \DateTime($item->close_dated_date);
        $compareDate = $closeDate < $today ? $closeDate->format('Y-m-d') : $today->format('Y-m-d');
        $direction = $settings->expirationDate == 'closest_date' ? 'asc' : 'desc';
        return $this->db->fetchOOSExpirationDate($item->product_id, $storeId, $companyId, $compareDate, $direction, $settings->maxDate);
    }
}
