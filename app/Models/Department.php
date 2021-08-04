<?php
declare(strict_types=1);

namespace App\Models;

class Department
{
    public $departmentId;

    public $departmentRule;
    public $categoryRule;

    public $department;
    public $wildcardDeptMatch;
    public $partialDeptMatch;

    public $category;
    public $wildcardCatMatch;
    public $partialCatMatch;

    public $skip;

    public function __construct(?string $departmentId, string $department, string $category, $skip)
    {
        $this->departmentId = $departmentId;

        $this->departmentRule = $department;
        $this->categoryRule = $category;

        $this->department = strtolower($department);
        $this->wildcardDeptMatch = $department === '%';
        $this->partialDeptMatch = $department !== '%' && strpos($department, '%') !== false;
        if ($this->partialDeptMatch) {
            $this->department = str_replace('%', '', $this->department);
        }

        $this->category = strtolower($category);
        $this->wildcardCatMatch = $category === '%';
        $this->partialCatMatch = $category !== '%' && strpos($category, '%') !== false;
        if ($this->partialCatMatch) {
            $this->category = str_replace('%', '', $this->category);
        }

        $this->skip = intval($skip) === 1;
    }

    public function matchDepartment(string $input): bool
    {
        $department = strtolower($input);

        if ($this->wildcardDeptMatch) {
            return true;
        }

        if ($this->partialDeptMatch) {
            return strpos($department, $this->department) !== false;
        }

        if ($this->department === $department) {
            return true;
        }

        return false;
    }

    public function matchCategory(string $input): bool
    {
        $category = strtolower($input);

        if ($this->wildcardCatMatch) {
            return true;
        }

        if ($this->partialCatMatch) {
            return strpos($category, $this->category) !== false;
        }

        if ($this->category === $category) {
            return true;
        }

        return false;
    }
}
