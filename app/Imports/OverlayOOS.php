<?php

namespace App\Imports;

use App\Models\OverlayNewSettings;
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
    private $readClosestDateStmt;
    private $readFurthestDateStmt;

    public function __construct(Api $api, Database $db)
    {
        $this->proxy = $api;
        $this->db = $db;

        $this->readClosestDateStmt = $this->database->prepare(
            "select i.expiration_date from inventory_items i
            inner join locations l on l.location_id = i.location_id
            inner join stores s on s.store_id = l.store_id
            where s.company_id = :company_id and i.product_id = :product_id and l.store_id <> :store_id
            and i.disco = 0 and i.flag IS NULL and i.close_dated_date > :close_dated_date
            order by i.expiration_date"
        );

        $this->readFurthestDateStmt = $this->database->prepare(
            "select i.expiration_date from inventory_items i
            inner join locations l on l.location_id = i.location_id
            inner join stores s on s.store_id = l.store_id
            where s.company_id = :company_id and i.product_id = :product_id and l.store_id <> :store_id
            and i.disco = 0 and i.flag IS NULL and i.close_dated_date > :close_dated_date
            order by i.expiration_date desc"
        );
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
            $date = $this->getExpirationDate($item, $settings->useClosestDate, $companyId, $inventory->store_id, $today);

            $this->proxy->writeInventoryExpiration($item->inventory_item_id, $date);
            $inventoryCount++;
        }

        $finalCount = $this->db->getOosInventoryCount($companyId, $settings->excludeStores, $settings->excludeDepts);

        $output = "initial: " . count($inventory) . ", final: $finalCount";

        $this->db->updateOverlayResultsRow($resultId, $total, $inventoryCount, 0, $output);

        $this->db->completeImport($importStatusId, 1, 0, '');
    }

    private function getImportSettings(string $companyId): OverlayNewSettings
    {
        $result = $this->db->fetchCustomImportSettings($this->key, $companyId);
        return new OverlayNewSettings($result);
    }

    private function getExpirationDate($item, $closestDate, $storeId, $companyId, \DateTime $today)
    {
        $closeDate = new \DateTime($item['close_dated_date']);
        $compareDate = $closeDate < $today ? $closeDate->format('Y-m-d') : $today->format('Y-m-d');

        if ($closestDate) {
            return $this->getNextClosestDate($item['product_id'], $storeId, $companyId, $compareDate);
        } else {
            return $this->getNextFurthestDate($item['product_id'], $storeId, $companyId, $compareDate);
        }
    }

    private function getNextClosestDate($productId, $storeId, $companyId, $date)
    {
        return $this->db->execPreparedFetchOne(
            $this->readClosestDateStmt,
            [
                'company_id' => $companyId,
                'store_id' => $storeId,
                'product_id' => $productId,
                'close_dated_date' => $date,
            ]
        );
    }

    private function getNextFurthestDate($productId, $storeId, $companyId, $date)
    {
        return $this->db->execPreparedFetchOne(
            $this->readFurthestDateStmt,
            [
                'company_id' => $companyId,
                'store_id' => $storeId,
                'product_id' => $productId,
                'close_dated_date' => $date,
            ]
        );
    }
}
