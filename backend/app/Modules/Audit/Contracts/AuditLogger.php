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

    /**
     * Records the difference between two raw attribute snapshots of a record (`Model::getAttributes()` before and
     * after the write; `[]` before = created). Only fields whose value changed are kept, then masked like record().
     * Nothing changed → no row.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, scalar|null>|null  $meta  non-personal context only
     */
    public function recordDiff(string $entityType, int $entityId, AuditAction $action, array $before, array $after, ?array $meta = null): void;
}
