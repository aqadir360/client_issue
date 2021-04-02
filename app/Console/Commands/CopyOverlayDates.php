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
    protected $description = 'Fills in product descriptions for skip list';

    /** @var Api */
    private $proxy;

    /** @var Database */
    public $db;

    public function handle()
    {
        $this->db = new Database();
        $this->db->setDbName('all_companies_db');
        $this->proxy = new Api();

        $stores = [
            '09e9bb7e-3afb-11eb-ac18-080027205eeb',
            '0a87bc66-3afb-11eb-9592-080027205eeb',
            '0b144bc2-3afb-11eb-af74-080027205eeb',
            '0bb09aa4-3afb-11eb-950c-080027205eeb',
            '0c4cc8de-3afb-11eb-be07-080027205eeb',
            '0edd035c-3afb-11eb-ba3a-080027205eeb',
            '16da4ee8-3afb-11eb-8c1a-080027205eeb',
            '17697168-3afb-11eb-bd36-080027205eeb',
            '17e838b8-3afb-11eb-b31a-080027205eeb',
            '22cadb64-3afb-11eb-943e-080027205eeb',
            '2562c562-3afb-11eb-ac37-080027205eeb',
            '29b80d70-3afb-11eb-b766-080027205eeb',
            '2a52f1b4-3afb-11eb-a828-080027205eeb',
            '35700190-3afb-11eb-95d4-080027205eeb',
            '3970c356-3afb-11eb-a42f-080027205eeb',
            '3d1d3048-3afb-11eb-be77-080027205eeb',
            '43c7eb9a-3afb-11eb-8574-080027205eeb',
            '47a0697c-3afb-11eb-8568-080027205eeb',
            '87e54f20-3afb-11eb-82d4-080027205eeb',
            '88818908-3afb-11eb-8c2b-080027205eeb',
            '89ba5106-3afb-11eb-898a-080027205eeb',
            '8ada3b14-3afb-11eb-b9c7-080027205eeb',
            '8b658e6c-3afb-11eb-a460-080027205eeb',
            '8cd8a252-3afb-11eb-823e-080027205eeb',
            '8d794bbc-3afb-11eb-9065-080027205eeb',
            '8ea29674-3afb-11eb-9f85-080027205eeb',
            '9105ce72-3afb-11eb-a960-080027205eeb',
            '91999a58-3afb-11eb-904b-080027205eeb',
            '9406c8ba-3afb-11eb-8ab9-080027205eeb',
            '94974f02-3afb-11eb-a78a-080027205eeb',
            '9979476e-3afb-11eb-b7ca-080027205eeb',
            '9ac16ba6-3afb-11eb-beea-080027205eeb',
            '9ca5cdc2-3afb-11eb-b89d-080027205eeb',
            '9dfd8c32-3afb-11eb-b45b-080027205eeb',
            '9e84fb4a-3afb-11eb-a411-080027205eeb',
            'a010f572-3afb-11eb-b214-080027205eeb',
            'a0d315d6-3afa-11eb-a44c-080027205eeb',
            'a0dbd9e0-3afb-11eb-806c-080027205eeb',
            'a1f52f20-3afb-11eb-90da-080027205eeb',
            'a7e8dd9c-3afa-11eb-89a1-080027205eeb',
            'aab5cd5a-3afa-11eb-a9d0-080027205eeb',
            'ae1ad562-3afa-11eb-a7de-080027205eeb',
            'b1a3667c-3afa-11eb-865b-080027205eeb',
            'bd395d16-3afa-11eb-934a-080027205eeb',
            'bdd4e90c-3afa-11eb-b697-080027205eeb',
            'be662f7a-3afa-11eb-8a85-080027205eeb',
            'c1c417f4-3afa-11eb-b334-080027205eeb',
            'c245a274-3afa-11eb-9331-080027205eeb',
            'cbb0a188-3afa-11eb-9da9-080027205eeb',
            'cebb4d24-3afa-11eb-a2c3-080027205eeb',
            'cf5e9dd0-3afa-11eb-a39d-080027205eeb',
            'd1c0b9d2-3afa-11eb-8b31-080027205eeb',
            'd61dcd76-3afa-11eb-accc-080027205eeb',
            'de0902e4-3afa-11eb-a87c-080027205eeb',
            'deb4daf6-3afa-11eb-91f6-080027205eeb',
            'df513446-3afa-11eb-ad3f-080027205eeb',
            'dfcae9b2-3afa-11eb-9de7-080027205eeb',
        ];

        foreach ($stores as $store) {
            $this->fixCloseDatedItemCounts($store);
        }
    }

    private function fixCloseDatedItemCounts(string $storeId)
    {
        echo $storeId . PHP_EOL;
        $inventory = $this->db->fetchCloseDatedInventory($storeId);
        $total = count($inventory);
        $max = $total * .7;
        $updated = 0;
        $minDate = new \DateTime();
        $minDate->add(new \DateInterval('P1M'));
        $companyId = '96bec4fe-098f-0e87-2563-11a36e6447ae';

        foreach ($inventory as $item) {
            $closestDate = $this->db->fetchNextClosestDate(
                $item->product_id,
                $companyId,
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
//        $stores = $this->db->getListParams($fromStores);

        $products = $this->db->fetchProductsWithoutDates();

        $minDate = new \DateTime();
        $minDate->add(new \DateInterval('P2W'));

        foreach ($products as $product) {
            // Check for the next closest date
            $closestDate = $this->db->fetchNextClosestDate(
                $product->product_id,
                '96bec4fe-098f-0e87-2563-11a36e6447ae',
                $minDate->format('Y-m-d')
            );

            if ($closestDate && $closestDate->expiration_date) {
                $this->db->addNextClosestDate($product->product_id, $closestDate->expiration_date);
            }
        }
    }
}
