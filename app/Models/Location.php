<?php
declare(strict_types=1);

namespace App\Models;

class Location
{
    public $aisle;
    public $section;
    public $shelf;
    public $valid;

    public function __construct(string $aisle = '', string $section = '', string $shelf = '')
    {
        $this->aisle = $aisle;
        $this->section = $section;
        $this->shelf = $shelf;
        $this->valid = false;
    }

    public function __toString()
    {
        return trim($this->aisle . " " . $this->section . " " . $this->shelf);
    }
}
