<?php

declare(strict_types=1);

namespace App\Modules\Audit\Contracts;

use App\Modules\Audit\DTO\AuditFilter;
use App\Modules\Audit\DTO\AuditRecord;
use App\Modules\Audit\Models\AuditEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

interface AuditLogRepository
{
    public function store(AuditRecord $record, ?int $actorId): void;

    /** @return LengthAwarePaginator<int, AuditEntry> */
    public function search(AuditFilter $filter): LengthAwarePaginator;

    /**
     * History of one entity plus related entities (e.g. a candidate and its applications).
     *
     * @param  array<string, list<int>>  $entities  entity type → ids
     * @return LengthAwarePaginator<int, AuditEntry>
     */
    public function history(array $entities, int $page, int $perPage): LengthAwarePaginator;

    /** @return list<array{id: int, name: string}> users that appear in the log */
    public function actors(): array;

    /** @return list<string> */
    public function entityTypes(): array;

    /** Deletes at most $limit rows older than $before (one bounded statement); returns how many. */
    public function purgeOlderThan(Carbon $before, int $limit): int;
}
