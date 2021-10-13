<?php
declare(strict_types=1);

namespace App\Imports\Overlay\Settings;

// Custom settings:
// - store_from: store_id to copy from (if empty, copy from all)
// - store_exclude: store_id to skip copy to
// - dept_exclude: department_id to skip copy to
// - expiration_date: date copy type (closest, closest_non, furthest, date_range)
// - start_date: start date for date_range calculation
// - end_date: end date for date_range calculation

class NewMapper
{
    public $copyFrom = [];
    public $excludeStores = [];
    public $excludeDepts = [];
    public $maxDate;
    public $compareDate;
    public $expirationDate = 'closest';
    public $startDate = null;
    public $endDate = null;

    public function __construct(array $result)
    {
        $maxDate = new \DateTime();
        $this->compareDate = $maxDate->format('Y-m-d');
        $maxDate->add(new \DateInterval('P5Y'));
        $this->maxDate = $maxDate->format('Y-m-d');

        foreach ($result as $row) {
            switch ($row->type) {
                case 'store_from':
                    $this->copyFrom[] = $row->value;
                    break;
                case 'store_exclude':
                    $this->excludeStores[] = $row->value;
                    break;
                case 'dept_exclude':
                    $this->excludeDepts[] = $row->value;
                    break;
                case 'expiration_date':
                    $this->expirationDate = $row->value;
                    break;
                case 'start_date':
                    $this->startDate = $row->value;
                    break;
                case 'end_date':
                    $this->endDate = $row->value;
                    break;
            }
        }
    }
}
