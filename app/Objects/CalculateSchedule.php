<?php

namespace App\Objects;

class CalculateSchedule
{
    /** @var Database */
    private $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    public function calculateNextRun(
        $importScheduleId,
        $daily,
        $weekDay,
        $monthDay,
        $startHour,
        $startMinute,
        $archivedAt
    ) {
        if ($archivedAt !== null) {
            // Subscription is no longer active
            return;
        }

        $date = new \DateTime();

        if (intval($daily) === 1) {
            $date->add(new \DateInterval('P1D'));
        } else {
            if ($weekDay !== null) {
                $date->modify('next ' . $this->getWeekDay($weekDay));
            } elseif ($monthDay !== null) {
                $date->setDate($date->format('Y'), (intval($date->format('m')) + 1), $monthDay);
            }
        }

        $date->setTimezone(new \DateTimeZone('CST'));
        $date->setTime($startHour, $startMinute);

        $date->setTimezone(new \DateTimeZone('UTC'));
        $this->database->insertNewJob($importScheduleId, $date->format('Y-m-d H:i:s'));
    }

    private function getWeekDay(int $day)
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
