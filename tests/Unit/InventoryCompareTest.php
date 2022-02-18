<?php

namespace Tests\Unit;

use App\Models\Location;
use App\Models\Product;
use App\Objects\Api;
use App\Objects\ImportManager;
use App\Objects\InventoryCompare;
use PHPUnit\Framework\TestCase;

class InventoryCompareTest extends TestCase
{
    protected $proxy;
    protected $importManager;
    protected $inventoryCompare;

    protected function setUp(): void
    {
        $this->proxy = $this->createMock(Api::class);
        $this->proxy->method('validResponse')->willReturn(true);

        $this->importManager = $this->createMock(ImportManager::class);
        $this->importManager->method('getProxy')->willReturn($this->proxy);
        $this->importManager->method('companyId')->willReturn('companyId');

        $this->inventoryCompare = new InventoryCompare($this->importManager, 'storeId');
    }

    public function testSetFileInventoryItem()
    {
        $this->importManager
            ->expects($this->once())
            ->method('outputContent')
            ->with("1 inventory items in file");

        $product = new Product('upc');
        $location = new Location('aisle', 'section', 'shelf');

        $this->inventoryCompare->setFileInventoryItem($product, $location, 'deptId');

        $this->assertEquals($this->inventoryCompare->fileInventoryCount(), 1);
    }

//    public function testSetExistingInventory()
//    {
//        $this->proxy
//            ->expects($this->once())
//            ->method('fetchAllInventory')
//            ->with('companyId', 'storeId')
//            ->willReturn((object)['data' => (object)['items' => array(
//                (object)[
//                    'barcode' => '0',
//                    'inventoryItemId' => 'inventoryItemId',
//                    'expirationDate' => 'expirationDate',
//                    'status' => 'status',
//                    'aisle' => 'aisle1',
//                    'section' => 'section1',
//                    'shelf' => 'shelf',
//                    'departmentId' => 'departmentId',
//                    'found' => false,],
//                (object)[
//                    'barcode' => '1',
//                    'inventoryItemId' => 'inventoryItemId',
//                    'expirationDate' => 'expirationDate',
//                    'status' => 'status',
//                    'aisle' => 'aisle1',
//                    'section' => 'section1',
//                    'shelf' => 'shelf',
//                    'departmentId' => 'departmentId',
//                    'found' => false,],
//                (object)[
//                    'barcode' => '2',
//                    'inventoryItemId' => 'inventoryItemId',
//                    'expirationDate' => 'expirationDate',
//                    'status' => 'status',
//                    'aisle' => 'aisle1',
//                    'section' => 'section2',
//                    'shelf' => 'shelf',
//                    'departmentId' => 'departmentId',
//                    'found' => false,])]]);
//
//        $this->importManager
//            ->expects($this->once())
//            ->method('outputContent')
//            ->with("3 existing inventory items");
//
//        $this->inventoryCompare->setExistingInventory();
//    }
//
//    public function testCompareInventorySetsEmpty()
//    {
//        $this->importManager
//            ->expects($this->once())
//            ->method('outputContent')
//            ->with("Disco percent: 0%");
//
//        $this->importManager
//            ->expects($this->never())
//            ->method('updateInventoryLocation');
//
//        $this->inventoryCompare->compareInventorySets();
//    }
//
//    public function testCompareInventorySetsFileOnly()
//    {
//        $this->importManager
//            ->expects($this->exactly(2))
//            ->method('outputContent')
//            ->withConsecutive(
//                array("Disco percent: 0%"),
//                array("1 inventory items in file"));
//
//        $this->importManager
//            ->expects($this->never())
//            ->method('updateInventoryLocation');
//
//        $this->importManager
//            ->expects($this->once())
//            ->method('isInSkipList')
//            ->with('0')
//            ->willReturn(false);
//
//        $this->importManager
//            ->expects($this->once())
//            ->method('recordSkipped');
//
//        $this->importManager
//            ->expects($this->never())
//            ->method('fetchProduct');
//
//        $this->importManager
//            ->expects($this->never())
//            ->method('implementationScan');
//
//        $product = new Product('upc');
//        $location = new Location('aisle', 'section', 'shelf');
//
//        $this->inventoryCompare->setFileInventoryItem($product, $location, 'deptId');
//
//        $this->inventoryCompare->compareInventorySets();
//
//        $this->assertEquals(1, $this->inventoryCompare->fileInventoryCount());
//    }
//
//    public function testCompareInventorySetsExistingOnly()
//    {
//        $this->proxy
//            ->expects($this->once())
//            ->method('fetchAllInventory')
//            ->with('companyId', 'storeId')
//            ->willReturn((object)['data' => (object)['items' => array(
//                (object)[
//                    'barcode' => '0',
//                    'inventoryItemId' => 'inventoryItemId',
//                    'expirationDate' => 'expirationDate',
//                    'status' => 'status',
//                    'aisle' => 'aisle',
//                    'section' => 'section',
//                    'shelf' => 'shelf',
//                    'departmentId' => 'departmentId',
//                    'found' => false,])]]);
//
//        $this->importManager
//            ->expects($this->exactly(3))
//            ->method('outputContent')
//            ->withConsecutive(
//                array("1 existing inventory items"),
//                array("Skipping attempt to discontinue 100.0% of inventory."),
//                array("0 inventory items in file"));
//
//        $this->importManager
//            ->expects($this->never())
//            ->method('updateInventoryLocation');
//
//        $this->importManager
//            ->expects($this->never())
//            ->method('isInSkipList');
//
//        $this->importManager
//            ->expects($this->never())
//            ->method('recordSkipped');
//
//        $this->importManager
//            ->expects($this->never())
//            ->method('fetchProduct');
//
//        $this->importManager
//            ->expects($this->never())
//            ->method('implementationScan');
//
//        $this->inventoryCompare->setExistingInventory();
//
//        $this->inventoryCompare->compareInventorySets();
//
//        $this->assertEquals(0, $this->inventoryCompare->fileInventoryCount());
//    }
//
//    public function testCompareInventorySetsTotalMatch()
//    {
//        $this->proxy
//            ->expects($this->once())
//            ->method('fetchAllInventory')
//            ->with('companyId', 'storeId')
//            ->willReturn((object)['data' => (object)['items' => array(
//                (object)[
//                    'barcode' => '0',
//                    'inventoryItemId' => 'inventoryItemId',
//                    'expirationDate' => 'expirationDate',
//                    'status' => 'status',
//                    'aisle' => 'aisle',
//                    'section' => 'section',
//                    'shelf' => 'shelf',
//                    'departmentId' => 'departmentId',
//                    'found' => false,])]]);
//
//        $this->importManager
//            ->expects($this->exactly(2))
//            ->method('outputContent')
//            ->withConsecutive(
//                array("1 existing inventory items"),
//                array("Disco percent: 0%"));
//
//        $this->importManager
//            ->expects($this->never())
//            ->method('updateInventoryLocation');
//
//        $this->importManager
//            ->expects($this->once())
//            ->method('recordStatic');
//
//        $product = new Product('upc');
//        $location = new Location('aisle', 'section', 'shelf');
//
//        $this->inventoryCompare->setFileInventoryItem($product, $location, 'deptId');
//
//        $this->inventoryCompare->setExistingInventory();
//
//        $this->inventoryCompare->compareInventorySets();
//    }
//
//    public function testCompareInventorySetsInequalLocationMatch()
//    {
//        $this->proxy
//            ->expects($this->once())
//            ->method('fetchAllInventory')
//            ->with('companyId', 'storeId')
//            ->willReturn((object)['data' => (object)['items' => array(
//                (object)[
//                    'barcode' => '0',
//                    'inventoryItemId' => 'inventoryItemId',
//                    'expirationDate' => 'expirationDate',
//                    'status' => 'status',
//                    'aisle' => 'aisle',
//                    'section' => 'section',
//                    'shelf' => 'shelf',
//                    'departmentId' => 'departmentId',
//                    'found' => false,])]]);
//
//        $this->importManager
//            ->expects($this->exactly(2))
//            ->method('outputContent')
//            ->withConsecutive(
//                array("1 existing inventory items"),
//                array("Disco percent: 0%"));
//
//        $this->importManager
//            ->expects($this->once())
//            ->method('updateInventoryLocation');
//
//        $this->importManager
//            ->expects($this->never())
//            ->method('recordStatic');
//
//        $product = new Product('upc');
//        $location = new Location('aisle', 'section', 'shelf');
//
//        $this->inventoryCompare->setFileInventoryItem($product, $location, 'deptId');
//
//        $this->inventoryCompare->setExistingInventory();
//
//        $this->inventoryCompare->compareInventorySets();
//    }
}
