<?php

namespace App\Imports\Settings;

use App\Models\Location;

class VallartaSettings
{
    public function shouldSkipLocation(Location $location): bool
    {
        $aisle = strtolower($location->aisle);
        $section = strtolower($location->section);

        $excluded = [
            'zzz',
            'xxx',
            'out',
            '*80',
            '000',
            '999',
        ];

        if (empty($aisle) || empty($section)) {
            return true;
        }

        foreach ($excluded as $item) {
            if ($item == $aisle || $item == $section) {
                return true;
            }
        }

        return false;
    }

    // Discontinue any items that move to OUT or to no location
    public function shouldDisco(Location $location): bool
    {
        return empty(trim($location->aisle))
            || strtolower($location->aisle) == 'out'
            || strtolower($location->section) == 'out';
    }
}
