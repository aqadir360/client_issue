<?php

namespace App\Imports\Settings;

use App\Models\Location;

class PriceChopperSettings
{
    public static function parseLocation(array $data): Location
    {
        $aisle = trim($data[12]);

        // Include full aisle string when not beginning with AL (e.g. AL01 becomes 01, RX01 remains RX01).
        if (strpos($aisle, 'AL') === 0) {
            $aisle = substr($aisle, 2);
        }

        // Use the first character of Left or Right
        $side = trim($data[13]);
        if (strlen($side) > 1) {
            $side = substr($side, 0, 1);
        }

        // Plus the integer value of Y-Coord without rounding
        $decimal = strpos(trim($data[15]), ".");
        $position = ($decimal === false) ? trim($data[15]) : substr(trim($data[15]), 0, $decimal);

        $location = new Location($aisle, $side . $position);

        // Skip blank aisles.
        $location->valid = !empty($location->aisle);

        return $location;
    }
}
