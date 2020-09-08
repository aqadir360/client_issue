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
        $archivedAt
    ): ?string {
        if ($archivedAt !== null) {
            // Subscription is no longer active
            return null;
        }

        $date = new \DateTime();

        $date->setTimezone(new \DateTimeZone('CST'));
        $date->setTime($startHour, $startMinute);

        if (intval($daily) === 1) {
            $date->add(new \DateInterval('P1D'));
            $date->setTimezone(new \DateTimeZone('UTC'));
        } else {
            $date->setTimezone(new \DateTimeZone('UTC'));

            if ($weekDay !== null) {
                $date->modify('next ' . CalculateSchedule::getWeekDay($weekDay));
            } elseif ($monthDay !== null) {
                $date->setDate($date->format('Y'), (intval($date->format('m')) + 1), $monthDay);
            }
        }

        return $date->format('Y-m-d H:i:s');
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
