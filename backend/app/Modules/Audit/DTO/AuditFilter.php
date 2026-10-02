<?php

declare(strict_types=1);

namespace App\Modules\Audit\DTO;

use App\Modules\Audit\Enums\AuditSort;
use Illuminate\Support\Carbon;

/** Audit log search; sort/descending order the page (default: newest first). */
final readonly class AuditFilter
{
    public function __construct(
        public ?int $userId = null,
        public ?string $entityType = null,
        public ?string $action = null,
        public ?Carbon $from = null,
        public ?Carbon $to = null,
        public int $page = 1,
        public int $perPage = 20,
        public AuditSort $sort = AuditSort::Time,
        public bool $descending = true,
    ) {}
}
