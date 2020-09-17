<?php

namespace App\Objects;

class CalculateSchedule
{
    public static function calculateNextRun(
        $daily,
        $weekDay,
        $monthDay,
        $startHour,
        $startMinute,
        \DateTime $date,
        $archivedAt = null
    ): ?string {
        if ($archivedAt !== null) {
            // Subscription is no longer active
            return null;
        }

        $localDate = new \DateTime($date->format('Y-m-d'), new \DateTimeZone('America/Chicago'));
        $localDate->setTime($startHour, $startMinute);

        if (intval($daily) === 1) {
            $localDate->add(new \DateInterval('P1D'));
        } else {
            if ($weekDay !== null) {
                $weekDate = clone $localDate;
                $weekDate->modify(CalculateSchedule::getWeekDay($weekDay) . ' next week');
                $localDate->setDate($weekDate->format('Y'), $weekDate->format('m'), $weekDate->format('d'));
            } elseif ($monthDay !== null) {
                $localDate->add(new \DateInterval('P1M'));
                $localDate->setDate($localDate->format('Y'), $localDate->format('m'), $monthDay);
            }
        }

        $localDate->setTimezone(new \DateTimeZone('UTC'));
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
