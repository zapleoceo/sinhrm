<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Repositories;

use App\Modules\Recruiting\Contracts\TouchpointRepository;
use App\Modules\Recruiting\DTO\Scope;
use App\Modules\Recruiting\DTO\TimelineEntry;
use App\Modules\Recruiting\Enums\Channel;
use App\Modules\Recruiting\Enums\TimelineItemType;
use App\Modules\Recruiting\Models\StageChange;
use App\Modules\Recruiting\Models\Touchpoint;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class EloquentTouchpointRepository implements TouchpointRepository
{
    public function find(int $id): ?Touchpoint
    {
        return Touchpoint::query()->find($id);
    }

    public function findByExternalId(Channel $channel, string $externalId): ?Touchpoint
    {
        return Touchpoint::query()->where('channel', $channel->value)->where('external_id', $externalId)->first();
    }

    public function candidateIdByThread(Channel $channel, string $thread): ?int
    {
        $id = Touchpoint::query()
            ->where('channel', $channel->value)
            ->where('meta->thread', $thread)
            ->whereNotNull('candidate_id')
            ->orderByDesc('id')
            ->value('candidate_id');

        return $id === null ? null : (int) $id;
    }

    public function latestThreadOf(int $candidateId, Channel $channel): ?Touchpoint
    {
        return Touchpoint::query()
            ->where('candidate_id', $candidateId)
            ->where('channel', $channel->value)
            ->whereNotNull('meta->thread')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();
    }

    public function latestInbound(int $candidateId, Channel $channel): ?Touchpoint
    {
        return Touchpoint::query()
            ->where('candidate_id', $candidateId)
            ->where('channel', $channel->value)
            ->where('direction', 'in')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();
    }

    public function lastInboundAt(int $candidateId, Channel $channel): ?Carbon
    {
        $at = Touchpoint::query()
            ->where('candidate_id', $candidateId)
            ->where('channel', $channel->value)
            ->where('direction', 'in')
            ->max('occurred_at');

        return is_string($at) ? Carbon::parse($at) : null;
    }

    public function create(array $attributes): Touchpoint
    {
        return Touchpoint::query()->create($attributes);
    }

    public function update(Touchpoint $touchpoint, array $attributes): Touchpoint
    {
        $touchpoint->fill($attributes)->save();

        return $touchpoint;
    }

    public function timeline(int $candidateId, ?array $channels, bool $withStages, int $perPage): LengthAwarePaginator
    {
        $withTouches = $channels === null || $channels !== [];
        $touches = DB::table('touchpoints')
            ->selectRaw("'touchpoint' as type, id, occurred_at as at")
            ->where('candidate_id', $candidateId)
            ->whereNull('stage_change_id')
            ->when($channels !== null, fn ($q) => $q->whereIn('channel', array_map(
                static fn (Channel $c): string => $c->value,
                $channels ?? [],
            )));
        $stages = DB::table('stage_changes')
            ->join('applications', 'applications.id', '=', 'stage_changes.application_id')
            ->selectRaw("'stage_change' as type, stage_changes.id as id, stage_changes.at as at")
            ->where('applications.candidate_id', $candidateId);

        $union = match (true) {
            $withTouches && $withStages => $touches->unionAll($stages),
            $withStages => $stages,
            default => $touches,
        };

        /** @var Paginator<int, object{type: string, id: int|string, at: string}> $page */
        $page = DB::query()->fromSub($union, 't')
            ->orderByDesc('at')
            ->orderBy('type')
            ->orderByDesc('id')
            ->paginate($perPage);

        $rows = collect($page->items());
        $ids = static fn (TimelineItemType $type): array => $rows
            ->filter(static fn (object $r): bool => $r->type === $type->value)
            ->map(static fn (object $r): int => (int) $r->id)
            ->values()
            ->all();
        $touchModels = Touchpoint::query()->with('author')->whereKey($ids(TimelineItemType::Touchpoint))->get()->keyBy('id');
        $stageModels = StageChange::query()
            ->with(['fromStage', 'toStage', 'byUser', 'application.vacancy'])
            ->whereKey($ids(TimelineItemType::StageChange))
            ->get()
            ->keyBy('id');

        $entries = [];
        foreach ($rows as $row) {
            $type = TimelineItemType::from($row->type);
            $model = $type === TimelineItemType::Touchpoint ? $touchModels->get((int) $row->id) : $stageModels->get((int) $row->id);
            if ($model !== null) {
                $entries[] = new TimelineEntry($type, Carbon::parse($row->at), $model);
            }
        }

        return new Paginator($entries, $page->total(), $page->perPage(), $page->currentPage(), [
            'path' => Paginator::resolveCurrentPath(),
        ]);
    }

    public function inbox(Scope $scope, int $perPage): LengthAwarePaginator
    {
        return $this->inboxQuery($scope)
            ->with('author')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function isInboxVisible(Scope $scope, Touchpoint $touchpoint): bool
    {
        return $this->inboxQuery($scope, false)->whereKey($touchpoint->id)->exists();
    }

    /**
     * Restricted users see inbox messages they authored or that arrived on a line of their branches.
     *
     * @return Builder<Touchpoint>
     */
    private function inboxQuery(Scope $scope, bool $unmatchedOnly = true): Builder
    {
        return Touchpoint::query()
            ->when($unmatchedOnly, fn (Builder $q) => $q->whereNull('candidate_id'))
            ->when(! $scope->isUnrestricted(), fn (Builder $q) => $q->where(function (Builder $w) use ($scope): void {
                $w->where('author_id', $scope->userId)->orWhereIn('branch_id', $scope->branchIds ?? []);
            }));
    }
}
