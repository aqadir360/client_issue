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
            'e953f0d0-02c6-c948-9ebd-a4f4ab75ae10', // 168
        ];

        $fromStores = [];

        foreach ($stores as $storeId) {
            $this->overlayInventory($companyId, $storeId, $fromStores);
        }

        $this->proxy->triggerUpdateCounts($companyId);
    }

    private function fixCloseDatedItemCounts(string $companyId, string $storeId, array $fromStores)
    {
        $inventory = $this->db->fetchCloseDatedInventory($storeId, '2021-07-12');
        $total = count($inventory);
        $max = $total * .8;
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
        $minDate->add(new \DateInterval('P4D'));

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

    private function fetchHighCountStores($companyId)
    {
        $sql = "SELECT s.store_id, s.store_num, x.count
                FROM #t#.stores s
                INNER JOIN (
                select sum(cls_now_count) + sum(exp_now_count) as count, store_id from #t#.store_counts
                group by store_id
                ) x on x.store_id = s.store_id
                WHERE s.archived_at IS NULL and s.company_id = :company_id
                order by x.count desc";

        return $this->db->fetchFromCompanyDb($sql, [
            'company_id' => $companyId
        ]);
    }
}
