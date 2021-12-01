<?php

namespace App\Imports\Settings;

use App\Models\Location;

class PriceChopperSettings
{
    public static function parseLocation(array $data): Location
    {
        $aisle = trim($data[11]);

        // Include full aisle string when not beginning with AL (e.g. AL01 becomes 01, RX01 remains RX01).
        if (strpos($aisle, 'AL') === 0) {
            $aisle = substr($aisle, 2);
        }

        // Use the first character of Left or Right
        $side = trim($data[12]);
        if (strlen($side) > 1) {
            $side = substr($side, 0, 1);
        }

        // Plus the integer value of Y-Coord without rounding
        $decimal = strpos(trim($data[14]), ".");
        $position = ($decimal === false) ? trim($data[14]) : substr(trim($data[14]), 0, $decimal);

        $shelf = trim($data[15]);
        $location = new Location($aisle, $side . $position, $shelf);

        // Skip blank aisles.
        $location->valid = !empty($location->aisle);

        return $location;
    }
}
