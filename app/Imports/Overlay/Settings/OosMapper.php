<?php
declare(strict_types=1);

namespace App\Imports\Overlay\Settings;

// Custom settings:
// - store_from: store_id to copy from (if empty, copy from all)
// - store_exclude: store_id to skip copy to
// - dept_exclude: department_id to skip copy to
// - expirationDate: date copy type (closest_date, furthest_date, date_range)
// - startDate: start date for date_range calculation
// - endDate: end date for date_range calculation

class OosMapper
{
    public $copyFrom = [];
    public $excludeStores = [];
    public $excludeDepts = [];
    public $expirationDate = '';
    public $startDate = null;
    public $endDate = null;
    public $maxDate;

    public function __construct(array $result)
    {
        $maxDate = new \DateTime();
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
                case 'expirationDate':
                    $this->expirationDate = $row->value;
                    break;
                case 'startDate':
                    $this->startDate = $row->value;
                    break;
                case 'endDate':
                    $this->endDate = $row->value;
                    break;
            }
        }
    }
}
