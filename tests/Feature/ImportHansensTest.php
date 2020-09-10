<?php

namespace Tests\Feature;

use App\Imports\ImportHansens;
use App\Objects\Api;
use App\Objects\Database;
use App\Objects\ImportManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use phpDocumentor\Reflection\Types\Array_;
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
                array('10 inventory items in file'),
                array('0 existing inventory items'),
                array('Disco percent: 0%'));

        $importHansens = new ImportHansens($importManager);
        $importHansens->processFiles(array(__DIR__ . "/9166_PLA_test.csv"));

        $this->assertTrue(true);
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
                array('0 inventory items in file'),
                array('Skipping 9166 - Import file was empty'));

        $importHansens = new ImportHansens($importManager);
        $importHansens->processFiles(array(__DIR__ . "/9166_PLA_empty_test.csv"));
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

        $importHansens = new ImportHansens($importManager);
        $importHansens->processFiles(array(__DIR__ . "/9166_PLA_empty_test.csv"));
    }
}
