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
        /** @var list<int>|null only these employees (null = no restriction) */
        public ?array $onlyIds = null,
    ) {}

    /** @param  list<int>  $ids */
    public function restrictedTo(array $ids): self
    {
        return new self($this->q, $this->branchId, $this->departmentId, $this->positionId, $this->status, $this->managerId, $this->perPage, $ids);
    }
}
