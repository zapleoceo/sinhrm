<?php

declare(strict_types=1);

namespace App\Modules\Time\Repositories;

use App\Modules\Time\Contracts\TimeRepository;
use App\Modules\Time\Enums\TimesheetStatus;
use App\Modules\Time\Models\TimeEntry;
use App\Modules\Time\Models\Timesheet;
use App\Modules\Time\Models\WorkSchedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class EloquentTimeRepository implements TimeRepository
{
    public function find(int $id): ?Timesheet
    {
        return Timesheet::query()->with(['employee', 'entries', 'decider:id,name'])->find($id);
    }

    public function findWeek(int $employeeId, Carbon $weekStart): ?Timesheet
    {
        return Timesheet::query()->with(['entries', 'decider:id,name'])->where('employee_id', $employeeId)
            ->whereDate('week_start', $weekStart->toDateString())->first();
    }

    public function firstOrCreateWeek(int $employeeId, Carbon $weekStart): Timesheet
    {
        $existing = $this->findWeek($employeeId, $weekStart);
        if ($existing !== null) {
            return $existing;
        }
        try {
            Timesheet::query()->create(['employee_id' => $employeeId, 'week_start' => $weekStart->toDateString()]);
        } catch (UniqueConstraintViolationException) {
            // Created concurrently: re-read below.
        }
        $row = $this->findWeek($employeeId, $weekStart);
        assert($row instanceof Timesheet);

        return $row;
    }

    public function forEmployees(array $employeeIds, Carbon $fromWeek, Carbon $toWeek): Collection
    {
        if ($employeeIds === []) {
            return new Collection;
        }

        return Timesheet::query()->whereIn('employee_id', $employeeIds)
            ->whereDate('week_start', '>=', $fromWeek->toDateString())
            ->whereDate('week_start', '<=', $toWeek->toDateString())
            ->get();
    }

    public function hoursByDay(array $employeeIds, Carbon $from, Carbon $to): array
    {
        if ($employeeIds === []) {
            return [];
        }
        $rows = DB::table('time_entries as e')
            ->join('timesheets as t', 't.id', '=', 'e.timesheet_id')
            ->whereIn('t.employee_id', $employeeIds)
            ->whereDate('e.date', '>=', $from->toDateString())
            ->whereDate('e.date', '<=', $to->toDateString())
            ->groupBy('t.employee_id', 'e.date')
            ->selectRaw('t.employee_id, e.date, sum(e.hours) as hours')
            ->get();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->employee_id][substr((string) $r->date, 0, 10)] = (float) $r->hours;
        }

        return $out;
    }

    public function submitted(?array $employeeIds, ?int $exceptEmployeeId, int $limit): Collection
    {
        return Timesheet::query()->with(['employee', 'entries'])
            ->where('status', TimesheetStatus::Submitted->value)
            ->when($employeeIds !== null, fn (Builder $q) => $q->whereIn('employee_id', $employeeIds ?? []))
            ->when($exceptEmployeeId, fn (Builder $q, int $id) => $q->where('employee_id', '!=', $id))
            ->orderBy('week_start')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    public function replaceEntries(Timesheet $timesheet, array $entries): void
    {
        TimeEntry::query()->where('timesheet_id', $timesheet->id)->delete();
        $now = Carbon::now();
        $rows = array_map(static fn (array $e): array => ['timesheet_id' => $timesheet->id] + $e + ['created_at' => $now, 'updated_at' => $now], $entries);
        if ($rows !== []) {
            TimeEntry::query()->insert($rows);
        }
    }

    public function update(Timesheet $timesheet, array $attributes): void
    {
        $timesheet->fill($attributes)->save();
    }

    public function transition(Timesheet $timesheet, array $from, array $attributes): bool
    {
        $changed = Timesheet::query()->whereKey($timesheet->id)
            ->whereIn('status', array_map(static fn (TimesheetStatus $s): string => $s->value, $from))
            ->update($attributes + ['updated_at' => Carbon::now()]) === 1;
        if ($changed) {
            $timesheet->refresh();
        }

        return $changed;
    }

    public function schedules(): Collection
    {
        return WorkSchedule::query()->with('branch:id,name')->orderByRaw('case when branch_id is null then 0 else 1 end')->orderBy('branch_id')->get();
    }

    public function saveSchedule(?int $branchId, array $days, float $hoursPerDay): WorkSchedule
    {
        $row = WorkSchedule::query()->when($branchId === null, fn (Builder $q) => $q->whereNull('branch_id'), fn (Builder $q) => $q->where('branch_id', $branchId))->first()
            ?? new WorkSchedule(['branch_id' => $branchId]);
        $row->fill(['days' => $days, 'hours_per_day' => $hoursPerDay])->save();

        return $row;
    }

    public function deleteSchedule(int $branchId): void
    {
        WorkSchedule::query()->where('branch_id', $branchId)->delete();
    }

    public function transaction(callable $callback): mixed
    {
        return DB::transaction(fn (): mixed => $callback());
    }
}
