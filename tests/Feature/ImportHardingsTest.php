<?php

namespace Tests\Feature;

use App\Imports\ImportHardings;
use App\Objects\Database;
use App\Objects\ImportManager;
use Tests\TestCase;

class ImportHardingsTest extends TestCase
{
    public function testBasic()
    {
        $database = $this->createMock(Database::class);
        $database->method('startImport')->willReturn(0);

        $importManager = $this->createMock(ImportManager::class);

        $importManager
            ->expects($this->once())
            ->method('storeNumToStoreId')
            ->with('421')
            ->willReturn('storeID');

        $importManager
            ->expects($this->once())
            ->method('startNewFile');

        $importManager
            ->expects($this->once())
            ->method('completeFile');

        $importManager
            ->expects($this->exactly(3))
            ->method('outputContent')
            ->withConsecutive(
                ['10 inventory items in file'],
                ['0 existing inventory items'],
                ['Disco percent: 0%']);

        $importHardings = $this->getMockBuilder(ImportHardings::class)
            ->setConstructorArgs([$importManager])
            ->setMethods(['getFilesToImport'])
            ->getMock();

        $importHardings
            ->expects($this->once())
            ->method('getFilesToImport')
            ->willReturn([__DIR__ . "/ImportHardings/PLU421HGM20200918_test.txt"]);

        $importHardings->importUpdates();
    }

    public function testEmpty()
    {
        $database = $this->createMock(Database::class);
        $database->method('startImport')->willReturn(0);

        $importManager = $this->createMock(ImportManager::class);

        $importManager
            ->expects($this->once())
            ->method('storeNumToStoreId')
            ->with('421')
            ->willReturn('storeID');

        $importManager
            ->expects($this->once())
            ->method('startNewFile');

        $importManager
            ->expects($this->once())
            ->method('completeFile');

        $importManager
            ->expects($this->exactly(2))
            ->method('outputContent')
            ->withConsecutive(
                ['0 inventory items in file'],
                ['Skipping 421 - Import file was empty']);

        $importHardings = $this->getMockBuilder(ImportHardings::class)
            ->setConstructorArgs([$importManager])
            ->setMethods(['getFilesToImport'])
            ->getMock();

        $importHardings
            ->expects($this->once())
            ->method('getFilesToImport')
            ->willReturn([__DIR__ . "/ImportHardings/PLU421HGM20200918_empty_test.txt"]);

        $importHardings->importUpdates();
    }

    public function testStoreNotFound()
    {
        $database = $this->createMock(Database::class);
        $database->method('startImport')->willReturn(0);

        $importManager = $this->createMock(ImportManager::class);

        $importManager
            ->expects($this->once())
            ->method('storeNumToStoreId')
            ->with('421')
            ->willReturn(false);

        $importManager
            ->expects($this->once())
            ->method('startNewFile');

        $importManager
            ->expects($this->once())
            ->method('completeFile');

        $importManager
            ->expects($this->once())
            ->method('outputContent')
            ->with('Invalid Store 421');

        $importHardings = $this->getMockBuilder(ImportHardings::class)
            ->setConstructorArgs([$importManager])
            ->setMethods(['getFilesToImport'])
            ->getMock();

        $importHardings
            ->expects($this->once())
            ->method('getFilesToImport')
            ->willReturn([__DIR__ . "/ImportHardings/PLU421HGM20200918_test.txt"]);

        $importHardings->importUpdates();
    }
}
