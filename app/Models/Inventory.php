<?php
declare(strict_types=1);

namespace App\Models;

class Inventory
{
    public $product;
    public $location;
    public $departmentId;

    public function __construct(
        Product $product,
        Location $location,
        string $departmentId
    ) {
        $this->product = $product;
        $this->location = $location;
        $this->departmentId = $departmentId;
    }

    public function matchingLocation($aisle, $section, $shelf): bool
    {
        return $this->location->aisle === $aisle
            && $this->location->section === $section
            && $this->location->shelf === $shelf;
    }
}
