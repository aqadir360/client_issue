<?php

namespace App\Console\Commands;

use App\Objects\Api;
use App\Objects\Database;
use Illuminate\Console\Command;
use Log;

// Overlays dates for all new items with closest non-expired date within company
class CopyNewDates extends Command
{
    protected $signature = 'dcp:copy_new_dates';
    protected $description = 'Copies new dates';

    /** @var Api */
    private $proxy;

    /** @var Database */
    public $db;

    public function handle()
    {
        $this->db = new Database();
        $this->db->setDbName('all_companies_db');
        $this->proxy = new Api();

        $this->overlayInventory('96bec4fe-098f-0e87-2563-11a36e6447ae');
    }

    private function overlayInventory(string $companyId)
    {
        // Fetch all new inventory grouped by products
        $products = $this->fetchNewCompanyProducts();

        $total = count($products);
        echo $total . PHP_EOL;
        $updatedCount = 0;
        $inventoryCount = 0;
        $skipped = 0;

        foreach ($products as $product) {
            // Check for the next closest date
            $closestDate = $this->fetchClosestDate($product->product_id, $companyId, '2021-04-29');

            if ($closestDate && $closestDate->expiration_date) {
                $updatedCount++;

                // Write the date for all inventory items
                $inventory = $this->db->fetchNewCompanyInventory($product->product_id);

                foreach ($inventory as $item) {
                    $this->proxy->writeInventoryExpiration($companyId, $item->inventory_item_id, $closestDate->expiration_date);
                    $inventoryCount++;
                }

                echo $product->product_id . " found " . count($inventory) . PHP_EOL;
            } else {
                echo $product->product_id . " skipped " . PHP_EOL;
                $skipped++;
            }
        }

        echo "From $total total products, updated $updatedCount dates for $inventoryCount items" . PHP_EOL;
    }

    public function fetchNewCompanyProducts()
    {
        $sql = "select distinct p.product_id from #t#.products p
                inner join (
                    select i.product_id from #t#.inventory_items i
                    inner join #t#.locations l on l.location_id = i.location_id
                    where i.flag = 'NEW'
                    group by i.product_id
                    order by count(i.inventory_item_id) desc
                ) x on x.product_id = p.product_id
                where p.no_expiration = 0";

        return $this->db->fetchFromCompanyDb($sql, []);
    }

    public function fetchClosestDate(string $productId, string $companyId, string $compareDate)
    {
        $sql = "select i.expiration_date from #t#.inventory_items i
            inner join #t#.locations l on l.location_id = i.location_id
            inner join #t#.stores s on l.store_id = s.store_id
            where i.product_id = :product_id and s.company_id = :company_id
            and i.close_dated_date > :compare_date and i.expiration_date is not null and i.flag is null and i.disco = 0
            order by i.expiration_date asc ";

        return $this->db->fetchOneFromCompanyDb($sql, [
            'product_id' => $productId,
            'company_id' => $companyId,
            'compare_date' => $compareDate
        ]);
    }
}
