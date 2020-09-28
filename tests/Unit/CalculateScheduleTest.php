<?php

namespace Tests\Unit;

use App\Objects\CalculateSchedule;
use App\Objects\Database;
use PHPUnit\Framework\TestCase;

class CalculateScheduleTest extends TestCase
{
    public function testCreateNextRun()
    {
        $database = $this->createMock(Database::class);

        $schedule = new \StdClass;
        $schedule->once = null;
        $schedule->daily = true;
        $schedule->week_day = null;
        $schedule->month_day = null;
        $schedule->start_hour = 1;
        $schedule->start_minute = 4;

        $database->expects($this->once())
            ->method('fetchImportSchedule')
            ->with(1)
            ->willReturn($schedule);

        $database->expects($this->never())
            ->method('archiveSchedule');

        $database->expects($this->once())
            ->method('insertNewJob')
            ->with(1);

        CalculateSchedule::createNextRun($database, 1);
    }

    public function testCreateNextRunOneTime()
    {
        $database = $this->createMock(Database::class);

        $schedule = new \StdClass;
        $schedule->once = '2020-01-01';

        $database->expects($this->once())
            ->method('fetchImportSchedule')
            ->with(1)
            ->willReturn($schedule);

        $database->expects($this->once())
            ->method('archiveSchedule')
            ->with(1);

        $database->expects($this->never())
            ->method('insertNewJob');

        CalculateSchedule::createNextRun($database, 1);
    }

    public function testCalculateArchived()
    {
        $output = CalculateSchedule::calculateNextRun(
            1,
            null,
            null,
            4,
            5,
            new \DateTime('2020-01-10'),
            '2020-01-01'
        );

        $this->assertEquals(null, $output);
    }

    public function testCalculateDaily()
    {
        // Scheduled daily at midnight
        $output = CalculateSchedule::calculateNextRun(
            1,
            null,
            null,
            0,
            0,
            new \DateTime('2020-09-16 05:13:00')
        );

        $this->assertEquals('2020-09-17 05:00:00', $output);
    }

    public function testCalculateDailyMorning()
    {
        // Scheduled daily at midnight
        $output = CalculateSchedule::calculateNextRun(
            1,
            null,
            null,
            0,
            0,
            new \DateTime('2020-12-16 01:01:22')
        );

        $this->assertEquals('2020-12-17 06:00:00', $output);
    }

    public function testCalculateDailyEvening()
    {
        // Scheduled daily at midnight
        $output = CalculateSchedule::calculateNextRun(
            1,
            null,
            null,
            0,
            0,
            new \DateTime('2020-07-16 23:13:46')
        );

        $this->assertEquals('2020-07-17 05:00:00', $output);
    }

    public function testCalculateDailyNextMonth()
    {
        // Scheduled daily at midnight
        $output = CalculateSchedule::calculateNextRun(
            1,
            null,
            null,
            0,
            0,
            new \DateTime('2020-02-29 23:13:46')
        );

        $this->assertEquals('2020-03-01 06:00:00', $output);
    }

    public function testCalculateDailyNextYear()
    {
        // Scheduled daily at 2:30am
        $output = CalculateSchedule::calculateNextRun(
            1,
            null,
            null,
            2,
            30,
            new \DateTime('2020-12-31 23:13:46')
        );

        $this->assertEquals('2021-01-01 08:30:00', $output);
    }

    public function testCalculateWeeklyMonday()
    {
        // Scheduled for Monday nights at midnight
        $output = CalculateSchedule::calculateNextRun(
            0,
            1,
            null,
            0,
            15,
            new \DateTime('2020-09-07 05:15:03')
        );

        $this->assertEquals('2020-09-15 05:15:00', $output);
    }

    public function testCalculateWeeklyMondayDaylightSavings()
    {
        // Scheduled for Monday nights at midnight
        $output = CalculateSchedule::calculateNextRun(
            0,
            1,
            null,
            0,
            15,
            new \DateTime('2020-01-06 06:23:01')
        );

        $this->assertEquals('2020-01-14 06:15:00', $output);
    }

    public function testCalculateWeeklyTuesday()
    {
        // Scheduled for Tuesday nights at midnight
        $output = CalculateSchedule::calculateNextRun(
            0,
            2,
            null,
            0,
            13,
            new \DateTime('2020-09-09 05:28:03')
        );

        $this->assertEquals('2020-09-16 05:13:00', $output);
    }

    public function testCalculateWeeklyNextMonth()
    {
        // Scheduled for Friday at 4:30am
        $output = CalculateSchedule::calculateNextRun(
            0,
            4,
            null,
            4,
            30,
            new \DateTime('2020-07-31 11:28:03')
        );

        $this->assertEquals('2020-08-07 09:30:00', $output);
    }

    public function testCalculateMonthly()
    {
        $output = CalculateSchedule::calculateNextRun(
            0,
            null,
            22,
            8,
            15,
            new \DateTime('2020-01-22 15:10:22')
        );

        $this->assertEquals('2020-02-22 14:15:00', $output);
    }

    public function testCalculateMonthlyEvening()
    {
        $output = CalculateSchedule::calculateNextRun(
            0,
            null,
            2,
            23,
            50,
            new \DateTime('2020-08-03 05:11:33')
        );

        $this->assertEquals('2020-09-03 04:50:00', $output);
    }

    public function testCalculateMonthlyNextYear()
    {
        $output = CalculateSchedule::calculateNextRun(
            0,
            null,
            16,
            11,
            0,
            new \DateTime('2020-12-16 20:11:33')
        );

        $this->assertEquals('2021-01-16 17:00:00', $output);
    }
}
