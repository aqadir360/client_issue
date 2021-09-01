<?php
declare(strict_types=1);

namespace App\Imports\Overlay\Settings;

// Custom settings:
// - store_from: store_id to copy from (if empty, copy from all)
// - store_include: store_id to copy to
// - dept_exclude: department_id to skip copy to
// - target_count: minimum number of notifications remaining
// - compare_date: date to use when determining notification status
// - min_date: closest date to copy
// - max_date: furthest date to copy

class NotificationsMapper
{
    public $copyFrom = [];
    public $copyTo = [];
    public $excludeDepts = [];
    public $targetCount = 0;
    public $compareDate = null;
    public $minDate = null;
    public $maxDate = null;
    public $skipChecked = false;
    public $dateType = 'closest';

    public function __construct(array $result)
    {
        $compareDate = new \DateTime();
        $compareDate->add(new \DateInterval('P1D'));
        $this->compareDate = $compareDate->format('Y-m-d');

        foreach ($result as $row) {
            switch ($row->type) {
                case 'store_from':
                    $this->copyFrom[] = $row->value;
                    break;
                case 'store_include':
                    $this->copyTo[] = $row->value;
                    break;
                case 'dept_exclude':
                    $this->excludeDepts[] = $row->value;
                    break;
                case 'target_count':
                    $this->targetCount = $row->value;
                    break;
                case 'min_date':
                    $this->minDate = $row->value;
                    break;
                case 'max_date':
                    $this->maxDate = $row->value;
                    break;
                case 'compare_date':
                    $this->compareDate = $row->value;
                    break;
                case 'date_type':
                    $this->dateType = $row->value;
                    break;
                case 'skip_checked':
                    $this->skipChecked = intval($row->value) === 1;
                    break;
            }
        }
    }
}
