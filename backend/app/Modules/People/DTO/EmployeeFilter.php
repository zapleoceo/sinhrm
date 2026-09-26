<?php

declare(strict_types=1);

namespace App\Modules\People\DTO;

use App\Modules\People\Enums\EmployeeStatus;

/** Directory search. status null = working (active + on_leave). */
final readonly class EmployeeFilter
{
    public function __construct(
        public ?string $q = null,
        public ?int $branchId = null,
        public ?int $departmentId = null,
        public ?int $positionId = null,
        public ?EmployeeStatus $status = null,
        public ?int $managerId = null,
        public int $perPage = 50,
    ) {}
}
