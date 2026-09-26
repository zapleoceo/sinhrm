<?php

declare(strict_types=1);

namespace App\Modules\Audit\DTO;

use App\Modules\Audit\Enums\AuditAction;

/** A change ready to be stored: already masked, never holds a secret or personal value. */
final readonly class AuditRecord
{
    /**
     * @param  array<string, array{from: mixed, to: mixed}>|null  $changes
     * @param  array<string, scalar|null>|null  $meta
     */
    public function __construct(
        public string $entityType,
        public int $entityId,
        public AuditAction $action,
        public ?array $changes = null,
        public ?array $meta = null,
    ) {}
}
