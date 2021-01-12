<?php

namespace App\Imports\Overlay;

use App\Imports\Overlay\Settings\NewMapper as Settings;
use App\Objects\Api;
use App\Objects\Database;
use Log;

// Overlays dates for all new items with closest non-expired date within company
class OverlayNew
{
    /** @var Api */
    private $proxy;

    /** @var Database */
    public $db;

    protected $key = 'overlay_new';

    public function __construct(Api $api, Database $db)
    {
        $this->proxy = $api;
        $this->db = $db;
    }

    public function importUpdates(string $companyId, int $scheduleId)
    {
        $importStatusId = $this->db->startImport($scheduleId);
        $resultId = $this->db->insertResultsRow($importStatusId, "New Item Overlay");
        $settings = $this->getImportSettings($companyId);

        try {
            $this->overlayInventory($companyId, $settings, $resultId);
        } catch (\Exception $e) {
            $this->db->updateOverlayResultsRow($resultId, 0, 0, 0, $e->getMessage());
            $this->db->completeImport($importStatusId, 1, 0, $e->getMessage());
            echo $e->getMessage() . PHP_EOL;
            Log::error($e);
        }

        $this->db->completeImport($importStatusId, 1, 0, '');
    }

    private function overlayInventory(string $companyId, Settings $settings, $resultId)
    {
        // Fetch all new inventory grouped by products
        $products = $this->db->fetchNewCompanyProducts($companyId);

        $total = count($products);
        $updatedCount = 0;
        $inventoryCount = 0;
        $skipped = 0;

        foreach ($products as $product) {
            // Check for the next closest date
            $closestDate = $this->db->fetchClosestDate(
                $product->product_id,
                $companyId,
                $settings->copyFrom,
                'asc',
                $settings->maxDate
            );

            if ($closestDate && $closestDate->expiration_date) {
                $updatedCount++;

                // Write the date for all inventory items
                $inventory = $this->db->fetchNewCompanyInventory($product->product_id, $companyId, $settings->excludeStores, $settings->excludeDepts);

                foreach ($inventory as $item) {
                    $this->proxy->writeInventoryExpiration($item->inventory_item_id, $closestDate->expiration_date);
                    $inventoryCount++;
                }
            } else {
                $skipped++;
            }
        }

        $output = "From $total total products, updated $updatedCount dates for $inventoryCount items";

        $this->db->updateOverlayResultsRow($resultId, $total, $updatedCount, $skipped, $output);
    }

    private function getImportSettings(string $companyId): Settings
    {
        $result = $this->db->fetchCustomImportSettings($this->key, $companyId);
        return new Settings($result);
    }
}
