<?php

declare(strict_types=1);

namespace App\Modules\People\Enums;

/**
 * Sortable columns of the directory (GET /api/people?sort=…). A closed list: the value never reaches SQL as text,
 * the repository maps each case to its own ORDER BY.
 */
enum EmployeeSort: string
{
    case Name = 'name';
    case Position = 'position';
    case Department = 'department';
    case Branch = 'branch';
    case Manager = 'manager';
}
