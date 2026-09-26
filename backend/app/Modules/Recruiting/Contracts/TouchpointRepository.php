<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Contracts;

use App\Modules\Recruiting\DTO\Scope;
use App\Modules\Recruiting\DTO\TimelineEntry;
use App\Modules\Recruiting\Enums\Channel;
use App\Modules\Recruiting\Models\Touchpoint;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

interface TouchpointRepository
{
    public function find(int $id): ?Touchpoint;

    public function findByExternalId(Channel $channel, string $externalId): ?Touchpoint;

    /** Candidate of the latest linked touchpoint of a conversation (meta.thread) in the channel. */
    public function candidateIdByThread(Channel $channel, string $thread): ?int;

    /** Latest touchpoint of the candidate in the channel that carries a conversation id (meta.thread). */
    public function latestThreadOf(int $candidateId, Channel $channel): ?Touchpoint;

    /** When the candidate last wrote to us in the channel (direction in), e.g. for the WhatsApp 24-hour window. */
    public function lastInboundAt(int $candidateId, Channel $channel): ?Carbon;

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
