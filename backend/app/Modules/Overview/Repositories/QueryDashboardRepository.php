<?php

declare(strict_types=1);

namespace App\Modules\Overview\Repositories;

use App\Modules\Overview\Contracts\DashboardRepository;
use App\Modules\Recruiting\DTO\Scope;
use App\Modules\Recruiting\Enums\ApplicationStatus;
use App\Modules\Recruiting\Enums\Channel;
use App\Modules\Recruiting\Enums\StageKind;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Plain SQL aggregates (Postgres- and SQLite-compatible), same scoping rules as the Recruiting reports. */
final class QueryDashboardRepository implements DashboardRepository
{
    public function counts(Scope $scope, Carbon $staleBefore, Carbon $todayStart): array
    {
        $row = $this->applications($scope)
            ->where('a.status', ApplicationStatus::Active->value)
            ->selectRaw('count(*) as active')
            ->selectRaw('sum(case when coalesce(a.last_touch_at, a.created_at) < ? then 1 else 0 end) as stale', [$staleBefore])
            ->selectRaw('sum(case when a.created_at >= ? then 1 else 0 end) as new_today', [$todayStart])
            ->first();

        $inbox = DB::table('touchpoints as t')
            ->whereNull('t.candidate_id')
            ->when(! $scope->isUnrestricted(), fn (Builder $q) => $q->where(function (Builder $w) use ($scope): void {
                $w->where('t.author_id', $scope->userId)->orWhereIn('t.branch_id', $scope->branchIds ?? []);
            }))
            ->count();

        return [
            'active' => (int) ($row->active ?? 0),
            'stale' => (int) ($row->stale ?? 0),
            'unmatched_inbox' => $inbox,
            'new_today' => (int) ($row->new_today ?? 0),
        ];
    }

    public function funnel(Scope $scope): array
    {
        $rows = $this->applications($scope)
            ->join('pipeline_stages as s', 's.id', '=', 'a.stage_id')
            ->where('a.status', ApplicationStatus::Active->value)
            ->groupBy('s.name', 's.kind', 's.position')
            ->orderBy('s.position')
            ->orderBy('s.name')
            ->selectRaw('s.name as stage_name, s.kind as stage_kind, s.position, count(*) as cnt')
            ->get();

        return array_values($rows->map(static fn (object $r): array => [
            'stage_name' => (string) $r->stage_name,
            'stage_kind' => (string) $r->stage_kind,
            'position' => (int) $r->position,
            'count' => (int) $r->cnt,
        ])->all());
    }

    public function touchesByChannel(Scope $scope, Carbon $since): array
    {
        $rows = DB::table('touchpoints as t')
            ->leftJoin('applications as a', 'a.id', '=', 't.application_id')
            ->leftJoin('vacancies as v', 'v.id', '=', 'a.vacancy_id')
            ->where('t.channel', '!=', Channel::System->value)
            ->where('t.occurred_at', '>=', $since)
            ->when(! $scope->isUnrestricted(), fn (Builder $q) => $q->where(function (Builder $w) use ($scope): void {
                $ids = $scope->branchIds ?? [];
                $w->where('t.author_id', $scope->userId)->orWhereIn('t.branch_id', $ids)->orWhereIn('v.branch_id', $ids);
            }))
            ->groupBy('t.channel')
            ->orderByRaw('count(*) desc')
            ->orderBy('t.channel')
            ->selectRaw('t.channel, count(*) as cnt')
            ->get();

        return array_values($rows->map(static fn (object $r): array => ['channel' => (string) $r->channel, 'count' => (int) $r->cnt])->all());
    }

    public function meetingsInvolving(int $userId, array $interviewApplicationIds, Carbon $from, Carbon $to): array
    {
        // meta.start is an ISO-8601 string with an offset: a coarse text window (±1 day) in SQL keeps the query
        // portable (Postgres jsonb ->> / SQLite json_extract via the query builder), the exact window is checked below.
        $rows = DB::table('touchpoints as t')
            ->join('candidates as c', 'c.id', '=', 't.candidate_id')
            ->where('t.channel', Channel::Meeting->value)
            ->where('t.meta->start', '>=', $from->copy()->subDay()->toDateString())
            ->where('t.meta->start', '<', $to->copy()->addDays(2)->toDateString())
            ->where(function (Builder $w) use ($userId, $interviewApplicationIds): void {
                $w->where('t.author_id', $userId);
                if ($interviewApplicationIds !== []) {
                    $w->orWhereIn('t.application_id', $interviewApplicationIds);
                }
            })
            ->orderBy('t.id')
            ->get(['t.id', 't.candidate_id', 'c.full_name', 't.meta']);

        $meetings = [];
        foreach ($rows as $r) {
            $meta = is_string($r->meta) ? json_decode($r->meta, true) : (array) $r->meta;
            $start = is_array($meta) ? $this->time($meta['start'] ?? null) : null;
            if ($start === null || $start->lt($from) || $start->gt($to)) {
                continue;
            }
            assert(is_array($meta));
            $meetings[] = [
                'id' => (int) $r->id,
                'candidate_id' => (int) $r->candidate_id,
                'candidate_name' => (string) $r->full_name,
                'start' => $start,
                'end' => $this->time($meta['end'] ?? null),
                'title' => is_string($meta['title'] ?? null) ? $meta['title'] : null,
                'meeting_type' => is_string($meta['meeting_type'] ?? null) ? $meta['meeting_type'] : null,
            ];
        }
        usort($meetings, static fn (array $a, array $b): int => [$a['start']->getTimestamp(), $a['id']] <=> [$b['start']->getTimestamp(), $b['id']]);

        return $meetings;
    }

    public function reachedStages(Scope $scope, Carbon $since): array
    {
        $rows = $this->applications($scope)
            ->join('stage_changes as sc', 'sc.application_id', '=', 'a.id')
            ->join('pipeline_stages as s', 's.id', '=', 'sc.to_stage_id')
            ->groupBy('a.id', 'a.status', 'v.pipeline_id')
            ->havingRaw('min(sc.at) >= ?', [$since])
            ->selectRaw('v.pipeline_id, a.status, max(case when s.kind <> ? then s.position end) as max_position', [StageKind::Closed->value])
            ->get();

        $reached = [];
        foreach ($rows as $r) {
            if ($r->max_position === null) {
                continue; // only ever on a closed stage: no route to measure
            }
            $reached[] = [
                'pipeline_id' => (int) $r->pipeline_id,
                'max_position' => (int) $r->max_position,
                'active' => $r->status === ApplicationStatus::Active->value,
            ];
        }

        return $reached;
    }

    public function routeStages(): array
    {
        return array_values(DB::table('pipeline_stages')
            ->where('kind', '<>', StageKind::Closed->value)
            ->orderBy('pipeline_id')
            ->orderBy('position')
            ->get(['pipeline_id', 'name', 'kind', 'position'])
            ->map(static fn (object $r): array => [
                'pipeline_id' => (int) $r->pipeline_id,
                'name' => (string) $r->name,
                'kind' => (string) $r->kind,
                'position' => (int) $r->position,
            ])->all());
    }

    public function offerPaths(Scope $scope, Carbon $since, Carbon $until): array
    {
        $firstOffer = 'min(case when s.kind = ? then sc.at end)';
        $rows = $this->applications($scope)
            ->join('stage_changes as sc', 'sc.application_id', '=', 'a.id')
            ->join('pipeline_stages as s', 's.id', '=', 'sc.to_stage_id')
            ->groupBy('a.id')
            ->havingRaw($firstOffer.' >= ?', [StageKind::Hire->value, $since])
            ->havingRaw($firstOffer.' <= ?', [StageKind::Hire->value, $until])
            ->selectRaw('min(sc.at) as started_at, '.$firstOffer.' as offer_at', [StageKind::Hire->value])
            ->get();

        $paths = [];
        foreach ($rows as $r) {
            $paths[] = ['started_at' => Carbon::parse((string) $r->started_at), 'offer_at' => Carbon::parse((string) $r->offer_at)];
        }

        return $paths;
    }

    private function time(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (InvalidFormatException) {
            return null; // a malformed value written by an integration is skipped, not fatal for the home page
        }
    }

    private function applications(Scope $scope): Builder
    {
        return DB::table('applications as a')
            ->join('vacancies as v', 'v.id', '=', 'a.vacancy_id')
            ->when(! $scope->isUnrestricted(), fn (Builder $q) => $q->whereIn('v.branch_id', $scope->branchIds ?? []));
    }
}
