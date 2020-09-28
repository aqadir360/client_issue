<?php

namespace Tests\Feature;

use App\Imports\OverlayNewItems;
use App\Objects\Api;
use App\Objects\Database;
use Tests\TestCase;

class OverlayNewItemsTest extends TestCase
{
    public function testBasic()
    {
        $database = $this->createMock(Database::class);
        $api = $this->createMock(Api::class);

        $database->expects($this->once())
            ->method('startImport')
            ->with(1)
            ->willReturn(2);

        $database->expects($this->once())
            ->method('insertResultsRow')
            ->with(2, "New Item Overlay")
            ->willReturn(3);

        $database->expects($this->once())
            ->method('fetchCustomImportSettings')
            ->with('overlay_new', 'companyId')
            ->willReturn([]);

        $companyProductsResult = new \StdClass;
        $companyProductsResult->product_id = 'productId';

        $database->expects($this->once())
            ->method('fetchNewCompanyProducts')
            ->with('companyId')
            ->willReturn([
                $companyProductsResult,
            ]);

        $closestDateResult = new \StdClass;
        $closestDateResult->expiration_date = '2020-01-01';

        $database->expects($this->once())
            ->method('fetchClosestDate')
            ->with('productId', 'companyId', [], 'asc')
            ->willReturn($closestDateResult);

        $companyInventoryResult = new \StdClass;
        $companyInventoryResult->inventory_item_id = 'itemId';

        $database->expects($this->once())
            ->method('fetchNewCompanyInventory')
            ->with('productId', 'companyId', [], [])
            ->willReturn([
                $companyInventoryResult,
            ]);

        $api->expects($this->once())
            ->method('writeInventoryExpiration')
            ->with('itemId', '2020-01-01');

        $output = "From 1 total products, updated 1 dates for 1 items";
        $database->expects($this->once())
            ->method('updateOverlayResultsRow')
            ->with(3, 1, 1, 0, $output);

        $database->expects($this->once())
            ->method('completeImport')
            ->with(2, 1, 0, '');

        $overlay = new OverlayNewItems($api, $database);
        $overlay->importUpdates('companyId', 1);
    }

    public function testSettings()
    {
        $database = $this->createMock(Database::class);
        $api = $this->createMock(Api::class);

        $database->expects($this->once())
            ->method('startImport')
            ->with(1)
            ->willReturn(2);

        $database->expects($this->once())
            ->method('insertResultsRow')
            ->with(2, "New Item Overlay")
            ->willReturn(3);

        $database->expects($this->once())
            ->method('fetchCustomImportSettings')
            ->with('overlay_new', 'companyId')
            ->willReturn([
                $this->getSettingObject('store_from', 'storeIdOne'),
                $this->getSettingObject('store_exclude', 'storeIdTwo'),
                $this->getSettingObject('store_exclude', 'storeIdThree'),
                $this->getSettingObject('dept_exclude', 'deptIdOne'),
                $this->getSettingObject('dept_exclude', 'deptIdTwo'),
            ]);

        $companyProductsResult = new \StdClass;
        $companyProductsResult->product_id = 'productId';

        $database->expects($this->once())
            ->method('fetchNewCompanyProducts')
            ->with('companyId')
            ->willReturn([
                $companyProductsResult,
            ]);

        $closestDateResult = new \StdClass;
        $closestDateResult->expiration_date = '2020-01-01';

        $database->expects($this->once())
            ->method('fetchClosestDate')
            ->with('productId', 'companyId', ['storeIdOne'], 'asc')
            ->willReturn($closestDateResult);

        $companyInventoryResult = new \StdClass;
        $companyInventoryResult->inventory_item_id = 'itemId';

        $database->expects($this->once())
            ->method('fetchNewCompanyInventory')
            ->with('productId', 'companyId', ['storeIdTwo', 'storeIdThree'], ['deptIdOne', 'deptIdTwo'])
            ->willReturn([
                $companyInventoryResult,
            ]);

        $api->expects($this->once())
            ->method('writeInventoryExpiration')
            ->with('itemId', '2020-01-01');

        $output = "From 1 total products, updated 1 dates for 1 items";
        $database->expects($this->once())
            ->method('updateOverlayResultsRow')
            ->with(3, 1, 1, 0, $output);

        $database->expects($this->once())
            ->method('completeImport')
            ->with(2, 1, 0, '');

        $overlay = new OverlayNewItems($api, $database);
        $overlay->importUpdates('companyId', 1);
    }

    public function testSkipped()
    {
        $database = $this->createMock(Database::class);
        $api = $this->createMock(Api::class);

        $database->expects($this->once())
            ->method('startImport')
            ->with(1)
            ->willReturn(2);

        $database->expects($this->once())
            ->method('insertResultsRow')
            ->with(2, "New Item Overlay")
            ->willReturn(3);

        $database->expects($this->once())
            ->method('fetchCustomImportSettings')
            ->with('overlay_new', 'companyId')
            ->willReturn([
                $this->getSettingObject('store_exclude', 'storeIdTwo'),
                $this->getSettingObject('dept_exclude', 'deptIdOne'),
            ]);

        $companyProductsResult = new \StdClass;
        $companyProductsResult->product_id = 'productId';

        $database->expects($this->once())
            ->method('fetchNewCompanyProducts')
            ->with('companyId')
            ->willReturn([
                $companyProductsResult,
            ]);

        $database->expects($this->once())
            ->method('fetchClosestDate')
            ->with('productId', 'companyId', [], 'asc')
            ->willReturn(false);

        $database->expects($this->never())
            ->method('fetchNewCompanyInventory');

        $api->expects($this->never())
            ->method('writeInventoryExpiration');

        $output = "From 1 total products, updated 0 dates for 0 items";
        $database->expects($this->once())
            ->method('updateOverlayResultsRow')
            ->with(3, 1, 0, 1, $output);

        $database->expects($this->once())
            ->method('completeImport')
            ->with(2, 1, 0, '');

        $overlay = new OverlayNewItems($api, $database);
        $overlay->importUpdates('companyId', 1);
    }

    private function getSettingObject($type, $value)
    {
        $result = new \StdClass;
        $result->type = $type;
        $result->value = $value;
        return $result;
    }
}
