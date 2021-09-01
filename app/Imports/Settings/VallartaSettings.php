<?php

namespace App\Imports\Settings;

use App\Models\Location;

class VallartaSettings
{
    public function shouldSkipLocation(Location $location): bool
    {
        $aisle = trim(strtolower($location->aisle));
        $section = trim(strtolower($location->section));

        if (empty($aisle) || empty($section)) {
            return true;
        }

        if (strpos($location->aisle, 'O') !== false || strpos($location->aisle, 'X') !== false) {
            return true;
        }

        if (strpos($aisle, '*') !== false) {
            return true;
        }

        $excluded = [
            'zzz',
            'xxx',
            'out',
            '*80',
            '000',
            '998',
            '999',
        ];

        foreach ($excluded as $item) {
            if ($item === $aisle || $item === $section) {
                return true;
            }
        }

        return false;
    }

    // Discontinue any items that move to OUT or to no location
    public function shouldDisco(Location $location): bool
    {
        return empty(trim($location->aisle))
            || trim(strtolower($location->aisle)) === 'out'
            || trim(strtolower($location->section)) === 'out';
    }
}
