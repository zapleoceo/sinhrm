<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Contracts;

use App\Modules\Recruiting\DTO\Scope;
use App\Modules\Recruiting\DTO\TimelineEntry;
use App\Modules\Recruiting\Enums\Channel;
use App\Modules\Recruiting\Models\Touchpoint;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface TouchpointRepository
{
    public function find(int $id): ?Touchpoint;

    public function findByExternalId(Channel $channel, string $externalId): ?Touchpoint;

    /** @param  array<string, mixed>  $attributes */
    public function create(array $attributes): Touchpoint;

    /** @param  array<string, mixed>  $attributes */
    public function update(Touchpoint $touchpoint, array $attributes): Touchpoint;

    /**
     * Touchpoints and stage changes of a candidate, newest first. System touchpoints of stage changes are left out
     * (the stage change itself is shown). $channels = null → every channel; $withStages = include stage changes.
     *
     * @param  list<Channel>|null  $channels
     * @return LengthAwarePaginator<int, TimelineEntry>
     */
    public function timeline(int $candidateId, ?array $channels, bool $withStages, int $perPage): LengthAwarePaginator;

    /** @return LengthAwarePaginator<int, Touchpoint> unmatched (no candidate) touchpoints in scope, newest first */
    public function inbox(Scope $scope, int $perPage): LengthAwarePaginator;

    public function isInboxVisible(Scope $scope, Touchpoint $touchpoint): bool;
}
