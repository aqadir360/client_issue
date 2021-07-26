<?php

namespace App\Imports\Overlay;

use App\Imports\Overlay\Settings\NotificationsMapper as Settings;
use App\Objects\Api;
use App\Objects\Database;
use Log;

// Overlays dates for close dated and expiring items
class OverlayNotifications
{
    /** @var Api */
    private $proxy;

    /** @var Database */
    public $db;

    protected $key = 'overlay_notifications';

    public function __construct(Api $api, Database $db)
    {
        $this->proxy = $api;
        $this->db = $db;
    }

    public function importUpdates(string $dbName, string $importTypeId, string $companyId, int $scheduleId, int $jobId)
    {
        $importStatusId = $this->db->startImport($importTypeId, $jobId);
        $resultId = $this->db->insertResultsRow($importStatusId, "Notifications Overlay");
        $settings = $this->getImportSettings($scheduleId);

        try {
            $this->overlayInventory($dbName, $companyId, $settings, $resultId);
            $this->proxy->triggerUpdateCounts($companyId);
        } catch (\Exception $e) {
            $this->db->updateOverlayResultsRow($resultId, 0, 0, 0, $e->getMessage());
            $this->db->completeImport($importStatusId, 1, 0, $e->getMessage());
            Log::error($e->getMessage());
        }

        $this->db->completeImport($importStatusId, 1, 0, '');
    }

    private function overlayInventory(string $dbName, string $companyId, Settings $settings, $resultId)
    {
        $this->db->setDbName($dbName);

        $output = '';
        $updatedCount = $totalCount = $skipped = 0;

        $fromStores = $this->db->getListParams($settings->copyFrom);

        foreach ($settings->copyTo as $storeId) {
            $inventory = $this->fetchCloseDatedInventory($storeId, $settings->compareDate);

            $total = count($inventory);
            $totalCount += $total;
            $max = $total - $settings->targetCount;
            $updated = 0;

            foreach ($inventory as $item) {
                $closestDate = $this->fetchNextClosestDate(
                    $item->product_id,
                    $companyId,
                    $fromStores,
                    $settings->minDate,
                    $settings->maxDate
                );

                if ($closestDate && $closestDate->expiration_date) {
                    $updated++;
                    $this->proxy->writeInventoryExpiration($companyId, $item->inventory_item_id, $closestDate->expiration_date);
                }

                if ($updated > $max) {
                    break;
                }
            }

            $updatedCount += $updated;
            $output .= " $storeId updated $updated of $total ";
        }

        $this->db->updateOverlayResultsRow($resultId, $totalCount, $updatedCount, $skipped, $output);
    }

    private function getImportSettings(string $scheduleId): Settings
    {
        $result = $this->db->fetchCustomImportSettings($scheduleId);
        return new Settings($result);
    }

    public function fetchCloseDatedInventory($storeId, $compareDate)
    {
        $sql = "select inventory_item_id, product_id from #t#.inventory_items i
                inner join #t#.locations l on l.location_id = i.location_id
                where l.store_id = :store_id and i.close_dated_date < :close_dated
                and i.status = 'ONSHELF' and i.flag is null and i.disco = 0";
        return $this->db->fetchFromCompanyDb($sql, [
            'store_id' => $storeId,
            'close_dated' => $compareDate,
        ]);
    }

    public function fetchNextClosestDate(
        string $productId,
        string $companyId,
        string $fromStores,
        string $minDate,
        string $maxDate
    ) {
        $sql = "select i.expiration_date from #t#.inventory_items i
            inner join #t#.locations l on l.location_id = i.location_id
            inner join #t#.stores s on s.store_id = l.store_id
            where i.product_id = :product_id and i.close_dated_date > :min_date and i.close_dated_date < :max_date
            and i.flag is null and i.disco = 0 and i.expiration_date is not null";

        if (!empty($fromStores)) {
            $sql .= " and s.store_id IN ($fromStores) ";
        } else {
            $sql .= " and s.company_id = '$companyId' ";
        }

        $sql .= " order by i.expiration_date asc ";

        return $this->db->fetchOneFromCompanyDb($sql, [
            'product_id' => $productId,
            'min_date' => $minDate,
            'max_date' => $maxDate,
        ]);
    }
}
