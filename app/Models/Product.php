<?php
declare(strict_types=1);

namespace App\Models;

class Product
{
    public $barcode;
    public $isExistingProduct = false;
    public $productId = null;
    public $description = null;
    public $size = null;
    public $inventory = [];

    public function __construct(string $barcode)
    {
        $this->barcode = $barcode;
    }

    public function hasInventory(): bool
    {
        return count($this->inventory) > 0;
    }

    public function setDescription($input)
    {
        $this->description = ucwords(strtolower(trim($input)));
    }

    public function setSize($input)
    {
        $this->size = strtolower(trim($input));
    }

    public function setFromResponse($response)
    {
        $this->description = $response->product->description;
        $this->size = $response->product->size;
        $this->productId = $response->product->productId;
        $this->isValidBarcode = true;
        $this->isExistingProduct = true;
    }

    public function getMatchingInventoryItem(Location $location, ?string $deptId = null)
    {
        if (count($this->inventory) === 0) {
            return null;
        }

        if (count($this->inventory) === 1) {
            return $this->inventory[0];
        }

        // use exact match
        foreach ($this->inventory as $item) {
            if ($item->aisle == $location->aisle
                && $item->section == $location->section
                && ($deptId === null || $item->department_id == $deptId)) {
                return $item;
            }
        }

        // use aisle match
        foreach ($this->inventory as $item) {
            if ($item->aisle == $location->aisle) {
                return $item;
            }
        }

        // use department match
        foreach ($this->inventory as $item) {
            if ($item->department_id == $deptId) {
                return $item;
            }
        }

        // use any non markdown section item
        foreach ($this->inventory as $item) {
            if ($item->aisle != 'MKDN') {
                return $item;
            }
        }

        return null;
    }
}
