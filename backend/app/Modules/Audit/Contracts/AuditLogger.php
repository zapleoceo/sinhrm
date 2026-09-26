<?php

declare(strict_types=1);

namespace App\Modules\Audit\Contracts;

use App\Modules\Audit\Enums\AuditAction;

/** Entry point for modules that record an event by hand (role change, secret set, …). */
interface AuditLogger
{
    /**
     * @param  array<string, array{from: mixed, to: mixed}>|null  $changes  raw before→after; masked by the logger
     * @param  array<string, scalar|null>|null  $meta  non-personal context only
     */
    public function record(string $entityType, int $entityId, AuditAction $action, ?array $changes = null, ?array $meta = null, ?int $actorId = null): void;
}
