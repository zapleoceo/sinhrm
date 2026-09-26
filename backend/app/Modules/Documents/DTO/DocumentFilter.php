<?php

declare(strict_types=1);

namespace App\Modules\Documents\DTO;

use App\Modules\Documents\Enums\DocumentStatus;

/**
 * GET /api/documents filters. $employeeIds = null → every employee (admin); $withDrafts = false hides drafts
 * (only HR works with drafts).
 */
final readonly class DocumentFilter
{
    /** @param  list<int>|null  $employeeIds */
    public function __construct(
        public ?array $employeeIds = null,
        public ?int $employeeId = null,
        public ?DocumentStatus $status = null,
        public ?string $category = null,
        public bool $withDrafts = true,
    ) {}

    /** @param  list<int>|null  $ids */
    public function restrictedTo(?array $ids, bool $withDrafts): self
    {
        return new self($ids, $this->employeeId, $this->status, $this->category, $withDrafts);
    }
}
