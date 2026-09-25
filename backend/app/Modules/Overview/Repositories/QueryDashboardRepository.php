<?php

declare(strict_types=1);

namespace App\Modules\Overview\Repositories;

use App\Modules\Overview\Contracts\DashboardRepository;
use App\Modules\Recruiting\DTO\Scope;
use App\Modules\Recruiting\Enums\ApplicationStatus;
use App\Modules\Recruiting\Enums\Channel;
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

    private function applications(Scope $scope): Builder
    {
        return DB::table('applications as a')
            ->join('vacancies as v', 'v.id', '=', 'a.vacancy_id')
            ->when(! $scope->isUnrestricted(), fn (Builder $q) => $q->whereIn('v.branch_id', $scope->branchIds ?? []));
    }
}
