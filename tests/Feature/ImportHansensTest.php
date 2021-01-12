<?php

namespace Tests\Feature;

use App\Imports\ImportHansens;
use App\Objects\Database;
use App\Objects\ImportManager;
use Tests\TestCase;

class ImportHansensTest extends TestCase
{
    public function testBasic()
    {
        $database = $this->createMock(Database::class);
        $database->method('startImport')->willReturn(0);

        $importManager = $this->createMock(ImportManager::class);

        $importManager
            ->expects($this->once())
            ->method('storeNumToStoreId')
            ->with('9166')
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

        $importHansens = $this->getMockBuilder(ImportHansens::class)
            ->setConstructorArgs([$importManager])
            ->setMethods(['getFilesToImport'])
            ->getMock();

        $importHansens
            ->expects($this->once())
            ->method('getFilesToImport')
            ->willReturn([__DIR__ . "/ImportHansens/9166_PLA_test.csv"]);

        $importHansens->importUpdates();
    }

    public function testEmpty()
    {
        $database = $this->createMock(Database::class);
        $database->method('startImport')->willReturn(0);

        $importManager = $this->createMock(ImportManager::class);

        $importManager
            ->expects($this->once())
            ->method('storeNumToStoreId')
            ->with('9166')
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
                ['Skipping 9166 - Import file was empty']);

        $importHansens = $this->getMockBuilder(ImportHansens::class)
            ->setConstructorArgs([$importManager])
            ->setMethods(['getFilesToImport'])
            ->getMock();

        $importHansens
            ->expects($this->once())
            ->method('getFilesToImport')
            ->willReturn([__DIR__ . "/ImportHansens/9166_PLA_empty_test.csv"]);

        $importHansens->importUpdates();
    }

    public function testStoreNotFound()
    {
        $database = $this->createMock(Database::class);
        $database->method('startImport')->willReturn(0);

        $importManager = $this->createMock(ImportManager::class);

        $importManager
            ->expects($this->once())
            ->method('storeNumToStoreId')
            ->with('9166')
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
            ->with('Invalid Store 9166');

        $importHansens = $this->getMockBuilder(ImportHansens::class)
            ->setConstructorArgs([$importManager])
            ->setMethods(['getFilesToImport'])
            ->getMock();

        $importHansens
            ->expects($this->once())
            ->method('getFilesToImport')
            ->willReturn([__DIR__ . "/ImportHansens/9166_PLA_empty_test.csv"]);

        $importHansens->importUpdates();
    }
}
