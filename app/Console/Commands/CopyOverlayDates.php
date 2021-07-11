<?php

namespace App\Console\Commands;

use App\Objects\Api;
use App\Objects\Database;
use Illuminate\Console\Command;

// Gets the next closest date for the given product set
class CopyOverlayDates extends Command
{
    protected $signature = 'dcp:copy_dates';
    protected $description = 'Clones dates for high close dated count stores';

    /** @var Api */
    private $proxy;

    /** @var Database */
    public $db;

    public function handle()
    {
        $this->db = new Database();
        $this->db->setDbName('price_chopper');
        $this->proxy = new Api();

        $companyId = '6859ef83-7f11-05fe-0661-075be46276ec';

        $stores = [
            '491ad47e-4c56-0001-7b2b-59ca6d87fa0b', // 140
            'b48cffab-c7ef-b7bc-cb5d-9d0f7743b714', // 172
            'e01c107a-7188-f6f8-5f8d-980ec31fc67f', // 174
        ];

        $fromStores = [];

        foreach ($stores as $storeId) {
            $this->overlayInventory($companyId, $storeId, $fromStores);
        }

        $this->proxy->triggerUpdateCounts($companyId);
    }

    private function fixCloseDatedItemCounts(string $companyId, string $storeId, array $fromStores)
    {
        echo $storeId . PHP_EOL;
        $inventory = $this->db->fetchCloseDatedInventory($storeId, '2021-07-10');
        $total = count($inventory);
        echo $total . PHP_EOL;
        $max = $total - 218;
        $updated = 0;
        $minDate = new \DateTime();
        $minDate->add(new \DateInterval('P1M'));
        $stores = $this->db->getListParams($fromStores);

        foreach ($inventory as $item) {
            $closestDate = $this->db->fetchNextClosestDate(
                $item->product_id,
                $companyId,
                $stores,
                $minDate->format('Y-m-d')
            );

            if ($closestDate && $closestDate->expiration_date) {
                $updated++;
                $this->proxy->writeInventoryExpiration($companyId, $item->inventory_item_id, $closestDate->expiration_date);
            }

            if ($updated > $max) {
                break;
            }
        }

        echo "Updated $updated of $total" . PHP_EOL;
        $this->proxy->triggerUpdateCounts($companyId, $storeId);
    }

    private function overlayInventory(string $companyId, string $storeId, array $fromStores)
    {
        $stores = $this->db->getListParams($fromStores);

        $products = $this->db->fetchNewInventory($storeId);

        $minDate = new \DateTime();
        $minDate->add(new \DateInterval('P1D'));

        echo count($products) . PHP_EOL;

        foreach ($products as $product) {
            // Check for the next closest date
            $closestDate = $this->db->fetchNextClosestDate(
                $product->product_id,
                $companyId,
                $stores,
                $minDate->format('Y-m-d')
            );

            if ($closestDate && $closestDate->expiration_date) {
                echo $closestDate->expiration_date . PHP_EOL;
                $this->proxy->writeInventoryExpiration($companyId, $product->inventory_item_id, $closestDate->expiration_date);
            }
        }
    }
}
