<?php

declare(strict_types=1);

namespace App\Modules\Observability\Contracts;

use App\Modules\Observability\Models\ErrorEvent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;

/** Storage of the error log (error_events): the screen, the recorder and the retention job all go through it. */
interface ErrorEventRepository
{
    /**
     * Groups, newest (last_seen_at) first. $resolved: false — open only, true — resolved only, null — all.
     *
     * @return Collection<int, ErrorEvent>
     */
    public function list(?bool $resolved, int $limit): Collection;

    /** @throws ModelNotFoundException */
    public function findOrFail(int $id): ErrorEvent;

    public function save(ErrorEvent $event): void;

    /**
     * One statement: inserts a new group, or bumps the group with the same fingerprint (count + 1, message, route,
     * last user, last_seen_at, updated_at from $row) and reopens it if it was resolved.
     *
     * @param  array<string, mixed>  $row  a full error_events row (fingerprint, …, created_at, updated_at)
     */
    public function upsertGroup(array $row): void;

    /** Deletes groups last seen before $before; returns how many. */
    public function pruneNotSeenSince(Carbon $before): int;
}
