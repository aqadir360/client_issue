<?php

namespace Tests\Unit;

use App\Objects\Api;
use App\Objects\Database;
use App\Objects\FtpManager;
use App\Objects\ImportManager;
use PHPUnit\Framework\TestCase;

class ImportManagerTest extends TestCase
{
    public function testSkipList()
    {
        $database = $this->createMock(Database::class);
        $api = $this->createMock(Api::class);
        $ftpManager = $this->createMock(FtpManager::class);

        $skipListItem = new \StdClass;
        $skipListItem->barcode = '00123456';

        $database->expects($this->once())
            ->method('fetchStores')
            ->with('companyId')
            ->willReturn([]);

        $database->expects($this->once())
            ->method('fetchDepartments')
            ->with('companyId')
            ->willReturn([]);

        $database->expects($this->once())
            ->method('fetchSkipItems')
            ->with('companyId')
            ->willReturn([$skipListItem]);

        $importManager = new ImportManager(
            $api,
            $database,
            $ftpManager,
            'companyId',
            1,
            2
        );

        $importManager->setSkipList();

        $exists = $importManager->isInSkipList('00123456');
        $this->assertEquals(true, $exists);

        $exists = $importManager->isInSkipList('123456');
        $this->assertEquals(true, $exists);

        $exists = $importManager->isInSkipList('1234561');
        $this->assertEquals(false, $exists);
    }
}
