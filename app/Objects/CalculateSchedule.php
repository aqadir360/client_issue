<?php

namespace App\Objects;

// Any changes should be replicated in Admin project
use DateInterval;
use DateTime;
use DateTimeZone;

class CalculateSchedule
{
    // Chooses the next pending run from available schedules and inserts job
    public static function createNextRun(Database $db, $scheduleId)
    {
        $schedule = $db->fetchImportSchedule($scheduleId);

        if ($schedule !== null) {
            if ($schedule->once !== null) {
                $db->archiveSchedule($scheduleId);
            } else {
                $nextRun = CalculateSchedule::calculateNextRun(
                    $schedule->daily,
                    $schedule->week_day,
                    $schedule->month_day,
                    $schedule->start_hour,
                    $schedule->start_minute,
                    new DateTime()
                );

                if ($nextRun !== null) {
                    $db->insertNewJob($scheduleId, $nextRun);
                }
            }
        }
    }

    public static function calculateNextRun(
        $daily,
        $weekDay,
        $monthDay,
        $startHour,
        $startMinute,
        DateTime $date,
        $archivedAt = null
    ): ?string {
        if ($archivedAt !== null) {
            // Subscription is no longer active
            return null;
        }

        $localDate = new DateTime($date->format('Y-m-d'), new DateTimeZone('America/Chicago'));
        $localDate->setTime($startHour, $startMinute);

        if (intval($daily) === 1) {
            $localDate->add(new DateInterval('P1D'));
        } else {
            if ($weekDay !== null) {
                $weekDate = clone $localDate;
                $weekDate->modify(CalculateSchedule::getWeekDay($weekDay) . ' next week');
                $localDate->setDate($weekDate->format('Y'), $weekDate->format('m'), $weekDate->format('d'));
            } elseif ($monthDay !== null) {
                $localDate->add(new DateInterval('P1M'));
                $localDate->setDate($localDate->format('Y'), $localDate->format('m'), $monthDay);
            }
        }

        $localDate->setTimezone(new DateTimeZone('UTC'));
        return $localDate->format('Y-m-d H:i:s');
    }

    public static function getWeekDay(int $day): string
    {
        switch ($day) {
            case 0:
                return 'monday';
            case 1:
                return 'tuesday';
            case 2:
                return 'wednesday';
            case 3:
                return 'thursday';
            case 4:
                return 'friday';
            case 5:
                return 'saturday';
            case 6:
                return 'sunday';
        }
    }
}
