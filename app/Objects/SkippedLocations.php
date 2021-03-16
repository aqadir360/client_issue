<?php

namespace App\Objects;

class SkippedLocations
{
    private $aisles;
    private $sections;
    private $shelves;

    public function __construct(array $aisles, array $sections, array $shelves)
    {
        $this->aisles = $aisles;
        $this->sections = $sections;
        $this->shelves = $shelves;
    }

    public function shouldSkip($aisle, $section, $shelf): bool
    {
        foreach ($this->aisles as $item) {
            if ($item == $aisle) {
                return true;
            }
        }
        foreach ($this->sections as $item) {
            if ($item == $section) {
                return true;
            }
        }
        foreach ($this->shelves as $item) {
            if ($item == $shelf) {
                return true;
            }
        }

        return false;
    }
}
