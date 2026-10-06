<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Repositories;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Recruiting\Contracts\ReportRepository;
use App\Modules\Recruiting\DTO\DateRange;
use App\Modules\Recruiting\DTO\Scope;
use App\Modules\Recruiting\Enums\ApplicationStatus;
use App\Modules\Recruiting\Enums\Channel;
use App\Modules\Recruiting\Support\ApplicationVisibility;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/** Plain SQL aggregates (Postgres- and SQLite-compatible). */
final class QueryReportRepository implements ReportRepository
{
    public function touches(Scope $scope, DateRange $range): array
    {
        $rows = DB::table('touchpoints as t')
            ->leftJoin('users as u', 'u.id', '=', 't.author_id')
            ->leftJoin('applications as a', 'a.id', '=', 't.application_id')
            ->leftJoin('vacancies as v', 'v.id', '=', 'a.vacancy_id')
            ->where('t.channel', '!=', Channel::System->value)
            ->whereBetween('t.occurred_at', [$range->from, $range->to])
            ->when(! $scope->isUnrestricted(), fn (Builder $q) => $q->where(function (Builder $w) use ($scope): void {
                $ids = $scope->branchIds ?? [];
                $w->where('t.author_id', $scope->userId)->orWhereIn('t.branch_id', $ids)->orWhereIn('v.branch_id', $ids);
            }))
            ->groupBy('t.author_id', 'u.name', 't.channel', 't.via_product')
            ->orderBy('u.name')
            ->orderBy('t.channel')
            ->selectRaw('t.author_id, u.name as author_name, t.channel, t.via_product, count(*) as cnt')
            ->get();

        return array_values($rows->map(static fn (object $r): array => [
            'author_id' => $r->author_id === null ? null : (int) $r->author_id,
            'author_name' => $r->author_name === null ? null : (string) $r->author_name,
            'channel' => (string) $r->channel,
            'via_product' => (bool) $r->via_product,
            'count' => (int) $r->cnt,
        ])->all());
    }

    public function funnel(Scope $scope, DateRange $range, ?int $vacancyId): array
    {
        $rows = DB::table('applications as a')
            ->join('vacancies as v', 'v.id', '=', 'a.vacancy_id')
            ->join('pipeline_stages as s', 's.id', '=', 'a.stage_id')
            ->leftJoin('branches as b', 'b.id', '=', 'v.branch_id')
            ->leftJoin('users as u', 'u.id', '=', 'v.recruiter_id')
            ->whereBetween('a.created_at', [$range->from, $range->to])
            ->when(! $scope->isUnrestricted(), fn (Builder $q) => $q->whereIn('v.branch_id', $scope->branchIds ?? []))
            ->when($vacancyId, fn (Builder $q, int $id) => $q->where('v.id', $id))
            ->groupBy('v.id', 'v.title', 'v.status', 'v.opened_at', 'b.name', 'u.name', 's.id', 's.name', 's.kind', 's.position')
            ->orderBy('v.title')
            ->orderBy('v.id')
            ->orderBy('s.position')
            ->selectRaw('v.id as vacancy_id, v.title as vacancy_title, v.status as vacancy_status, v.opened_at, b.name as branch_name, u.name as recruiter_name, s.id as stage_id, s.name as stage_name, s.kind as stage_kind, s.position, count(*) as cnt')
            ->get();

        return array_values($rows->map(static fn (object $r): array => [
            'vacancy_id' => (int) $r->vacancy_id,
            'vacancy_title' => (string) $r->vacancy_title,
            // Card header of the funnel (additive fields; the stage rows are unchanged).
            'vacancy_status' => (string) $r->vacancy_status,
            'branch_name' => $r->branch_name !== null ? (string) $r->branch_name : null,
            'recruiter_name' => $r->recruiter_name !== null ? (string) $r->recruiter_name : null,
            'opened_at' => $r->opened_at !== null ? substr((string) $r->opened_at, 0, 10) : null,
            'stage_id' => (int) $r->stage_id,
            'stage_name' => (string) $r->stage_name,
            'stage_kind' => (string) $r->stage_kind,
            'position' => (int) $r->position,
            'count' => (int) $r->cnt,
        ])->all());
    }

    public function sources(Scope $scope, DateRange $range): array
    {
        // Candidates with at least one hired application (join, not a correlated subquery inside SUM: portable).
        $hired = DB::table('applications')
            ->select('candidate_id')
            ->where('status', ApplicationStatus::Hired->value)
            ->distinct();

        $rows = DB::table('candidates as c')
            ->leftJoinSub($hired, 'h', 'h.candidate_id', '=', 'c.id')
            ->whereBetween('c.created_at', [$range->from, $range->to])
            ->when(! $scope->isUnrestricted(), fn (Builder $q) => $this->candidatesInScope($q, $scope))
            ->groupBy('c.source')
            ->orderBy('c.source')
            ->selectRaw('c.source, count(*) as candidates, count(h.candidate_id) as hired')
            ->get();

        return array_values($rows->map(static fn (object $r): array => [
            'source' => (string) $r->source,
            'candidates' => (int) $r->candidates,
            'hired' => (int) $r->hired,
        ])->all());
    }

    public function rejectReasons(Scope $scope, DateRange $range): array
    {
        $rows = $this->rejected($scope, $range)
            ->leftJoin('reject_reasons as r', 'r.id', '=', 'a.reject_reason_id')
            ->groupBy('r.id', 'r.name')
            ->orderByRaw('count(*) desc')
            ->orderBy('r.name')
            ->selectRaw('r.id as reject_reason_id, r.name, count(*) as cnt')
            ->get();

        return array_values($rows->map(static fn (object $r): array => [
            'reject_reason_id' => $r->reject_reason_id === null ? null : (int) $r->reject_reason_id,
            'name' => $r->name === null ? null : (string) $r->name,
            'count' => (int) $r->cnt,
        ])->all());
    }

    public function rejectionBreakdown(Scope $scope, DateRange $range): array
    {
        // The rejecting step = the latest stage change into the application's current (reject) stage.
        $last = DB::table('stage_changes')->selectRaw('application_id, to_stage_id, max(id) as id')->groupBy('application_id', 'to_stage_id');
        $rows = $this->rejected($scope, $range)
            ->leftJoin('reject_reasons as r', 'r.id', '=', 'a.reject_reason_id')
            ->leftJoinSub($last, 'lc', fn (JoinClause $j): JoinClause => $j->on('lc.application_id', '=', 'a.id')->on('lc.to_stage_id', '=', 'a.stage_id'))
            ->leftJoin('stage_changes as sc', 'sc.id', '=', 'lc.id')
            ->leftJoin('pipeline_stages as fs', 'fs.id', '=', 'sc.from_stage_id')
            ->leftJoin('users as u', 'u.id', '=', 'sc.by_user_id')
            ->groupBy('r.id', 'r.name', 'fs.id', 'fs.name', 'fs.position', 'u.id', 'u.name')
            ->selectRaw('r.id as reject_reason_id, r.name, fs.id as stage_id, fs.name as stage_name, fs.position as stage_position, u.id as user_id, u.name as user_name, count(*) as cnt')
            ->get();

        return array_values($rows->map(static fn (object $r): array => [
            'reject_reason_id' => $r->reject_reason_id === null ? null : (int) $r->reject_reason_id,
            'name' => $r->name === null ? null : (string) $r->name,
            'stage_id' => $r->stage_id === null ? null : (int) $r->stage_id,
            'stage_name' => $r->stage_name === null ? null : (string) $r->stage_name,
            'stage_position' => $r->stage_position === null ? null : (int) $r->stage_position,
            'user_id' => $r->user_id === null ? null : (int) $r->user_id,
            'user_name' => $r->user_name === null ? null : (string) $r->user_name,
            'count' => (int) $r->cnt,
        ])->all());
    }

    public function recruitingUserIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return array_values(User::query()->whereIn('id', $userIds)->role(UserRole::valuesOf(UserRole::recruitingWriters()))
            ->orderBy('id')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all());
    }

    public function channels(Scope $scope, DateRange $range): array
    {
        $hired = DB::table('applications')->select('candidate_id')->where('status', ApplicationStatus::Hired->value)->distinct();
        // Applications that ever were on a "select" or "hire" stage (current stage or any step of the route).
        $advanced = ['select', 'hire'];
        $apps = DB::table('applications as a')
            ->join('pipeline_stages as st', 'st.id', '=', 'a.stage_id')
            ->groupBy('a.candidate_id')
            ->selectRaw('a.candidate_id, count(*) as applications')
            ->selectRaw('sum(case when st.kind in (?, ?) or exists (select 1 from stage_changes sc join pipeline_stages s2 on s2.id = sc.to_stage_id where sc.application_id = a.id and s2.kind in (?, ?)) then 1 else 0 end) as advanced', [...$advanced, ...$advanced]);

        $rows = DB::table('candidates as c')
            ->leftJoin('acquisition_channels as ch', 'ch.id', '=', 'c.channel_id')
            ->leftJoinSub($hired, 'h', 'h.candidate_id', '=', 'c.id')
            ->leftJoinSub($apps, 'ap', 'ap.candidate_id', '=', 'c.id')
            ->whereBetween('c.created_at', [$range->from, $range->to])
            ->when(! $scope->isUnrestricted(), fn (Builder $q) => $this->candidatesInScope($q, $scope))
            ->groupBy('c.channel_id', 'ch.code', 'ch.name', 'ch.type')
            ->orderBy('ch.name')
            ->selectRaw('c.channel_id, ch.code, ch.name, ch.type, count(*) as candidates, coalesce(sum(ap.applications), 0) as applications, coalesce(sum(ap.advanced), 0) as advanced, count(h.candidate_id) as hired')
            ->get();

        return array_values($rows->map(static fn (object $r): array => [
            'channel_id' => $r->channel_id === null ? null : (int) $r->channel_id,
            'code' => $r->code === null ? null : (string) $r->code,
            'name' => $r->name === null ? null : (string) $r->name,
            'type' => $r->type === null ? null : (string) $r->type,
            'candidates' => (int) $r->candidates,
            'applications' => (int) $r->applications,
            'advanced' => (int) $r->advanced,
            'hired' => (int) $r->hired,
        ])->all());
    }

    public function channelCosts(DateRange $range): array
    {
        return array_values(DB::table('acquisition_channel_costs')
            ->where('period_start', '<=', $range->to->toDateString())
            ->where('period_end', '>=', $range->from->toDateString())
            ->orderBy('id')
            ->get(['channel_id', 'period_start', 'period_end', 'amount'])
            ->map(static fn (object $r): array => [
                'channel_id' => (int) $r->channel_id,
                'period_start' => substr((string) $r->period_start, 0, 10),
                'period_end' => substr((string) $r->period_end, 0, 10),
                'amount' => (float) $r->amount,
            ])->all());
    }

    public function vacancySources(int $vacancyId): array
    {
        $rows = DB::table('applications as a')
            ->join('candidates as c', 'c.id', '=', 'a.candidate_id')
            ->leftJoin('acquisition_channels as ch', 'ch.id', '=', 'c.channel_id')
            ->where('a.vacancy_id', $vacancyId)
            ->groupBy('c.channel_id', 'ch.name', 'c.added_via')
            ->orderByRaw('count(*) desc')
            ->orderBy('ch.name')
            ->selectRaw('c.channel_id, ch.name, c.added_via, count(*) as cnt')
            ->get();

        return array_values($rows->map(static fn (object $r): array => [
            'channel_id' => $r->channel_id === null ? null : (int) $r->channel_id,
            'name' => $r->name === null ? null : (string) $r->name,
            'added_via' => $r->added_via === null ? null : (string) $r->added_via,
            'count' => (int) $r->cnt,
        ])->all());
    }

    /** Applications rejected in the range (closed_at) that the user may see (alias "a"; ApplicationVisibility). */
    private function rejected(Scope $scope, DateRange $range): Builder
    {
        $q = DB::table('applications as a')
            ->where('a.status', ApplicationStatus::Rejected->value)
            ->whereBetween('a.closed_at', [$range->from, $range->to]);
        ApplicationVisibility::constrain($q, $scope, 'a');

        return $q;
    }

    /** Candidates the scoped user may see: owned/created, or applied to a vacancy of their branches (alias "c"). */
    private function candidatesInScope(Builder $q, Scope $scope): Builder
    {
        return $q->where(function (Builder $w) use ($scope): void {
            $w->where('c.owner_id', $scope->userId)
                ->orWhere('c.created_by', $scope->userId)
                ->orWhereExists(fn (Builder $sub) => $sub->selectRaw('1')
                    ->from('applications as sa')
                    ->join('vacancies as sv', 'sv.id', '=', 'sa.vacancy_id')
                    ->whereColumn('sa.candidate_id', 'c.id')
                    ->whereIn('sv.branch_id', $scope->branchIds ?? []));
        });
    }
}
