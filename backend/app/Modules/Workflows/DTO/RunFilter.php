<?php

declare(strict_types=1);

namespace App\Modules\Workflows\DTO;

use App\Modules\Workflows\Enums\RunStatus;

/** GET /api/workflows/runs filters. $employeeIds = null → every employee (admin). */
final readonly class RunFilter
{
    /** @param  list<int>|null  $employeeIds */
    public function __construct(
        public ?array $employeeIds = null,
        public ?int $employeeId = null,
        public ?int $templateId = null,
        public ?RunStatus $status = null,
    ) {}

    /** @param  list<int>  $ids */
    public function restrictedTo(array $ids): self
    {
        return new self($ids, $this->employeeId, $this->templateId, $this->status);
    }
}
