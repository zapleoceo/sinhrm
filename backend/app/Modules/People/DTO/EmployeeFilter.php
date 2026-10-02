<?php

declare(strict_types=1);

namespace App\Modules\People\DTO;

use App\Modules\People\Enums\EmployeeSort;
use App\Modules\People\Enums\EmployeeStatus;

/**
 * Directory search. status null = working (active + on_leave); anyStatus = also terminated (picker only).
 * terminatedWithin narrows anyStatus: terminated rows only among these ids (a manager's subtree); null = all terminated.
 * Column filters (name, contact, manager) are "contains", case-insensitive; sort/descending order the page.
 */
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
        public bool $anyStatus = false,
        /** @var list<int>|null */
        public ?array $terminatedWithin = null,
        public ?string $name = null,
        public ?string $contact = null,
        public ?string $manager = null,
        public EmployeeSort $sort = EmployeeSort::Name,
        public bool $descending = false,
    ) {}

    /** @param  list<int>  $ids */
    public function restrictedTo(array $ids): self
    {
        return new self(
            q: $this->q,
            branchId: $this->branchId,
            departmentId: $this->departmentId,
            positionId: $this->positionId,
            status: $this->status,
            managerId: $this->managerId,
            perPage: $this->perPage,
            onlyIds: $ids,
            anyStatus: $this->anyStatus,
            terminatedWithin: $this->terminatedWithin,
            name: $this->name,
            contact: $this->contact,
            manager: $this->manager,
            sort: $this->sort,
            descending: $this->descending,
        );
    }
}
