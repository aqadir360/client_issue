<?php

namespace App\Console\Commands;

use App\Objects\Api;
use App\Objects\Database;
use Log;
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
        $this->db->setDbName('all_companies_db');
        $this->proxy = new Api();

        $stores = $this->db->fetchHighCountStores(1000);

        foreach ($stores as $store) {
            $this->fixCloseDatedItemCounts($store->store_id);
        }
    }

    private function fixCloseDatedItemCounts(string $storeId)
    {
        echo $storeId . PHP_EOL;
        $inventory = $this->db->fetchCloseDatedInventory($storeId, '2021-04-30');
        $total = count($inventory);
        $max = $total * .3;
        $updated = 0;
        $minDate = new \DateTime();
        $minDate->add(new \DateInterval('P1M'));
        $companyId = '96bec4fe-098f-0e87-2563-11a36e6447ae';

        foreach ($inventory as $item) {
            $closestDate = $this->db->fetchNextClosestDate(
                $item->product_id,
                $companyId,
                '',
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

    private function overlayInventory(string $dbName, array $fromStores)
    {
        $this->db->setDbName($dbName);
        $stores = $this->db->getListParams($fromStores);

        $products = $this->db->fetchProductsWithoutDates();

        $minDate = new \DateTime();
        $minDate->add(new \DateInterval('P2W'));

        foreach ($products as $product) {
            // Check for the next closest date
            $closestDate = $this->db->fetchNextClosestDate(
                $product->product_id,
                '96bec4fe-098f-0e87-2563-11a36e6447ae',
                $stores,
                $minDate->format('Y-m-d')
            );

            if ($closestDate && $closestDate->expiration_date) {
                $this->db->addNextClosestDate($product->product_id, $closestDate->expiration_date);
            }
        }
    }
}
