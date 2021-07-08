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
            '605f7938-e9a9-b979-42cf-0ab6428e16c0', // 45
            'c30c75a8-8724-04c4-7c80-3d9170644c35' // 196
        ];

        $fromStores = [
            '40bada9f-9296-acf3-114d-e631f6338e42',
            '48784336-16ef-c4f9-f37c-061813b61fff',
            'c935b3bd-28c9-73ad-b682-31092a523e85',
            'da2794e9-ed7e-4e61-fc18-f89425400376'
        ];

        foreach ($stores as $storeId) {
            $this->overlayInventory('price_chopper', $companyId, $storeId, $fromStores);
        }

        $this->proxy->triggerUpdateCounts($companyId);
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

    private function overlayInventory(string $dbName, string $companyId, string $storeId, array $fromStores)
    {
        $this->db->setDbName($dbName);
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
                $minDate->format('Y-m-d'),
                $dbName
            );

            if ($closestDate && $closestDate->expiration_date) {
                echo $closestDate->expiration_date . PHP_EOL;
                $this->proxy->writeInventoryExpiration($companyId, $product->inventory_item_id, $closestDate->expiration_date);
            }
        }
    }
}
