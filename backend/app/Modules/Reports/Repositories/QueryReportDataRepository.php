<?php

declare(strict_types=1);

namespace App\Modules\Reports\Repositories;

use App\Modules\Desk\Enums\CaseStatus;
use App\Modules\Perform\Enums\AssignmentStatus;
use App\Modules\Perform\Enums\CycleStatus;
use App\Modules\Perform\Enums\ObjectiveScope;
use App\Modules\Pulse\Enums\QuestionType;
use App\Modules\Pulse\Enums\WaveStatus;
use App\Modules\Recruiting\DTO\Scope;
use App\Modules\Recruiting\Enums\ApplicationStatus;
use App\Modules\Reports\Contracts\ReportDataRepository;
use App\Modules\TimeOff\Enums\LeaveRequestStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Plain query-builder reads; grouping by month/buckets happens in PHP (portable between Postgres and SQLite). */
final class QueryReportDataRepository implements ReportDataRepository
{
    public function employees(?array $employeeIds, ?int $branchId): array
    {
        return DB::table('employees as e')
            ->leftJoin('branches as b', 'b.id', '=', 'e.branch_id')
            ->leftJoin('departments as d', 'd.id', '=', 'e.department_id')
            ->when($employeeIds !== null, static fn (Builder $q) => $q->whereIn('e.id', $employeeIds ?? []))
            ->when($branchId !== null, static fn (Builder $q) => $q->where('e.branch_id', $branchId))
            ->orderBy('e.id')
            ->get(['e.id', 'e.branch_id', 'b.name as branch', 'd.name as department', 'e.hired_at', 'e.fired_at', 'e.birth_date'])
            ->map(static fn (object $r): array => [
                'id' => (int) $r->id,
                'branch_id' => $r->branch_id === null ? null : (int) $r->branch_id,
                'branch' => $r->branch === null ? null : (string) $r->branch,
                'department' => $r->department === null ? null : (string) $r->department,
                'hired_at' => substr((string) $r->hired_at, 0, 10),
                'fired_at' => $r->fired_at === null ? null : substr((string) $r->fired_at, 0, 10),
                'birth_date' => $r->birth_date === null ? null : substr((string) $r->birth_date, 0, 10),
            ])->values()->all();
    }

    public function approvedLeave(?array $employeeIds, Carbon $from, Carbon $to): array
    {
        return DB::table('leave_requests as r')
            ->join('leave_types as t', 't.id', '=', 'r.leave_type_id')
            ->where('r.status', LeaveRequestStatus::Approved->value)
            ->where('r.starts_on', '<=', $to->toDateString())
            ->where('r.ends_on', '>=', $from->toDateString())
            ->when($employeeIds !== null, static fn (Builder $q) => $q->whereIn('r.employee_id', $employeeIds ?? []))
            ->orderBy('r.starts_on')
            ->get(['r.employee_id', 't.name as leave_type', 'r.starts_on', 'r.ends_on', 'r.days'])
            ->map(static fn (object $r): array => [
                'employee_id' => (int) $r->employee_id,
                'leave_type' => (string) $r->leave_type,
                'starts_on' => substr((string) $r->starts_on, 0, 10),
                'ends_on' => substr((string) $r->ends_on, 0, 10),
                'days' => (float) $r->days,
            ])->values()->all();
    }

    public function balances(?array $employeeIds, int $limit): array
    {
        return DB::table('leave_balance_ledger as l')
            ->join('employees as e', 'e.id', '=', 'l.employee_id')
            ->join('leave_types as t', 't.id', '=', 'l.leave_type_id')
            ->where('t.tracks_balance', true)
            ->whereNull('e.fired_at')
            ->when($employeeIds !== null, static fn (Builder $q) => $q->whereIn('l.employee_id', $employeeIds ?? []))
            ->groupBy('e.id', 'e.full_name', 't.id', 't.name')
            ->orderBy('e.full_name')
            ->orderBy('t.name')
            ->limit($limit)
            ->selectRaw('e.full_name as employee, t.name as leave_type, sum(l.delta) as balance')
            ->get()
            ->map(static fn (object $r): array => [
                'employee' => (string) $r->employee,
                'leave_type' => (string) $r->leave_type,
                'balance' => round((float) $r->balance, 2),
            ])->values()->all();
    }

    public function deskCases(Carbon $from, Carbon $to): array
    {
        return DB::table('desk_cases as c')
            ->join('desk_categories as k', 'k.id', '=', 'c.category_id')
            ->whereBetween('c.created_at', [$from, $to])
            ->orderBy('c.id')
            ->get(['k.name as category', 'c.created_at', 'c.first_response_at', 'c.resolved_at', 'c.status', 'k.first_response_hours', 'k.resolve_hours'])
            ->map(static fn (object $r): array => [
                'category' => (string) $r->category,
                'created_at' => (string) $r->created_at,
                'first_response_at' => $r->first_response_at === null ? null : (string) $r->first_response_at,
                // A closed case without a resolution time still stopped the clock (defensive; the service sets both).
                'resolved_at' => $r->resolved_at === null ? null : (string) $r->resolved_at,
                'open' => in_array((string) $r->status, CaseStatus::openValues(), true),
                'first_response_hours' => $r->first_response_hours === null ? null : (int) $r->first_response_hours,
                'resolve_hours' => $r->resolve_hours === null ? null : (int) $r->resolve_hours,
            ])->values()->all();
    }

    public function assetsByStatus(): array
    {
        return DB::table('assets as a')
            ->leftJoin('asset_types as t', 't.id', '=', 'a.type_id')
            ->groupBy('a.status', 't.name')
            ->orderBy('a.status')
            ->orderBy('t.name')
            ->selectRaw('a.status as status, t.name as type, count(*) as assets, coalesce(sum(a.cost), 0) as cost')
            ->get()
            ->map(static fn (object $r): array => [
                'status' => (string) $r->status,
                'type' => $r->type === null ? null : (string) $r->type,
                'assets' => (int) $r->assets,
                'cost' => round((float) $r->cost, 2),
            ])->values()->all();
    }

    public function hiredApplications(Scope $scope, Carbon $from, Carbon $to): array
    {
        return DB::table('applications as a')
            ->join('vacancies as v', 'v.id', '=', 'a.vacancy_id')
            ->where('a.status', ApplicationStatus::Hired->value)
            ->whereNotNull('a.closed_at')
            ->whereBetween('a.closed_at', [$from, $to])
            ->when(! $scope->isUnrestricted(), static fn (Builder $q) => $q->whereIn('v.branch_id', $scope->branchIds ?? []))
            ->orderBy('v.title')
            ->get(['v.title as vacancy', 'a.created_at', 'a.closed_at'])
            ->map(static fn (object $r): array => [
                'vacancy' => (string) $r->vacancy,
                'created_at' => (string) $r->created_at,
                'closed_at' => (string) $r->closed_at,
            ])->values()->all();
    }

    public function objectives(?array $ownerIds, ?string $period): array
    {
        return DB::table('objectives')
            ->when($period !== null, static fn (Builder $q) => $q->where('period', $period))
            ->when($ownerIds !== null, static fn (Builder $q) => $q
                ->whereIn('scope', [ObjectiveScope::Personal->value, ObjectiveScope::Team->value])
                ->whereIn('owner_employee_id', $ownerIds ?? []))
            ->get(['scope', 'status', 'progress'])
            ->map(static fn (object $r): array => ['scope' => (string) $r->scope, 'status' => (string) $r->status, 'progress' => (int) $r->progress])
            ->values()->all();
    }

    public function reviewCycles(?array $subjectIds): array
    {
        $submitted = AssignmentStatus::Submitted->value;

        return DB::table('review_cycles as c')
            ->join('review_assignments as a', 'a.cycle_id', '=', 'c.id')
            ->whereIn('c.status', [CycleStatus::Active->value, CycleStatus::Closed->value])
            ->when($subjectIds !== null, static fn (Builder $q) => $q->whereIn('a.subject_employee_id', $subjectIds ?? []))
            ->groupBy('c.id', 'c.name', 'c.status')
            ->orderByDesc('c.id')
            ->selectRaw('c.name as cycle, c.status as status, count(*) as total, sum(case when a.status = ? then 1 else 0 end) as submitted', [$submitted])
            ->get()
            ->map(static fn (object $r): array => [
                'cycle' => (string) $r->cycle,
                'status' => (string) $r->status,
                'total' => (int) $r->total,
                'submitted' => (int) $r->submitted,
            ])->values()->all();
    }

    public function closedEnpsWaves(int $limit): array
    {
        $rows = DB::table('survey_waves as w')
            ->join('surveys as s', 's.id', '=', 'w.survey_id')
            ->where('w.status', WaveStatus::Closed->value)
            ->orderByDesc('w.ends_at')
            ->limit($limit)
            ->get(['w.id', 's.title', 's.questions', 'w.ends_at', 'w.min_group_size']);

        $out = [];
        foreach ($rows as $r) {
            $questions = json_decode((string) $r->questions, true);
            foreach (is_array($questions) ? $questions : [] as $q) {
                if (is_array($q) && ($q['type'] ?? null) === QuestionType::Enps->value) {
                    $out[] = [
                        'wave_id' => (int) $r->id,
                        'title' => (string) $r->title,
                        'ends_at' => substr((string) $r->ends_at, 0, 10),
                        'min_group' => max(1, (int) $r->min_group_size),
                        'question_id' => (string) ($q['id'] ?? ''),
                    ];
                    break;
                }
            }
        }

        return array_reverse($out);
    }
}
