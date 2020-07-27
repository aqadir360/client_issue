<?php

namespace App\Objects;

use App\Models\Department;

class DepartmentMapper
{
    private $departments = [];

    public function __construct(array $departments)
    {
        foreach ($departments as $department) {
            $this->departments[] = new Department(
                $department->department_id,
                $department->department,
                $department->category,
                $department->skip
            );
        }

        usort($this->departments, 'self::compare');
    }

    public function getMatchingDepartment(string $department, string $category): ?Department
    {
        $matchDept = [];

        foreach ($this->departments as $dept) {
            if ($dept->matchDepartment($department)) {
                $matchDept[] = $dept;
            }
        }

        $matchCat = [];

        foreach ($matchDept as $dept) {
            if ($dept->matchCategory($category)) {
                $matchCat[] = $dept;
            }
        }

        // Matches should be ordered from most to least specific
        if (count($matchCat) > 0) {
            return $matchCat[0];
        }

        return null;
    }

    // Orders department and category by exact match, partial match, and wildcard
    public static function compare(Department $one, Department $two)
    {
        if ($one->department === $two->department) {
            // do by category
            if ($one->wildcardCatMatch) {
                return 1;
            } elseif ($two->wildcardCatMatch) {
                return -1;
            }

            if ($one->partialCatMatch) {
                if ($two->partialCatMatch) {
                    return strcmp($one->category, $two->category);
                }
                return 1;
            } elseif ($two->partialCatMatch) {
                return -1;
            }

            return strcmp($one->category, $two->category);
        }

        if ($one->wildcardDeptMatch) {
            return 1;
        } elseif ($two->wildcardDeptMatch) {
            return -1;
        }

        if ($one->partialDeptMatch) {
            if ($two->partialDeptMatch) {
                return strcmp($one->department, $two->department);
            }
            return 1;
        } elseif ($two->partialDeptMatch) {
            return -1;
        }

        return strcmp($one->department, $two->department);
    }
}
