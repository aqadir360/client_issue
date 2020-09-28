<?php
declare(strict_types=1);

namespace App\Models;

// Custom settings:
// - store_from: store_id to copy from (if empty, copy from all)
// - store_exclude: store_id to skip copy to
// - dept_exclude: department_id to skip copy to
class OverlayNewSettings
{
    public $copyFrom = [];
    public $excludeStores = [];
    public $excludeDepts = [];

    public function __construct(array $result)
    {
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
            }
        }
    }
}
