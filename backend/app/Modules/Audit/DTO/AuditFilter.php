<?php

declare(strict_types=1);

namespace App\Modules\Audit\DTO;

use Illuminate\Support\Carbon;

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
    ) {}
}
