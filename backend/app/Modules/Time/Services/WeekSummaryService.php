<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\People\Models\Employee;
use App\Modules\Time\Contracts\TimeRepository;
use App\Modules\Time\DTO\Schedule;
use App\Modules\Time\Models\Timesheet;
use App\Modules\Time\Models\WorkSchedule;
use App\Modules\Time\Support\WeekCalculator;
use App\Modules\TimeOff\Contracts\LeaveRequestRepository;
use App\Modules\TimeOff\Contracts\LeaveSettingsRepository;
use App\Modules\TimeOff\Enums\HalfDay;
use App\Modules\TimeOff\Enums\LeaveRequestStatus;
use App\Modules\TimeOff\Enums\LeaveUnit;
use App\Modules\TimeOff\Models\LeaveRequest;
use Illuminate\Support\Carbon;

/**
 * Batch week figures for many employees (team view, reminders, reports): loads schedules, entries, approved leave
 * (TimeOff) and holidays once for the whole range, then runs WeekCalculator per employee and week.
 *
 * Schedule of an employee: People work_schedule {days, hours_per_day} → the branch schedule → the company default
 * (work_schedules.branch_id null) → 8 h Monday–Friday.
 * Leave: approved requests only; a day inside the request is a full day (fraction 1) except the half day of
 * half_day=start (first day) / end (last day) = 0.5; a one-day request in hours = hours ÷ hours_per_day (≤ 1).
 */
final readonly class WeekSummaryService
{
    public function __construct(
        private TimeRepository $time,
        private LeaveRequestRepository $leave,
        private LeaveSettingsRepository $settings,
    ) {}

    /**
     * @param  iterable<Employee>  $employees
     * @return array<int, array<string, array{week_start: string, status: string, timesheet_id: int|null, schedule: array{days: list<int>, hours_per_day: float, source: string}, days: list<array<string, mixed>>, expected: float, worked: float, overtime: float, missing: float, absence: float}>>
     *                                                                                                                                                                                                                                                                                                 employee id => [week start => summary]
     */
    public function summaries(iterable $employees, Carbon $fromWeek, Carbon $toWeek): array
    {
        $list = [];
        foreach ($employees as $e) {
            $list[$e->id] = $e;
        }
        if ($list === []) {
            return [];
        }
        $ids = array_keys($list);
        $from = WeekCalculator::weekStart($fromWeek);
        $to = WeekCalculator::weekStart($toWeek)->addDays(6);
        $schedules = $this->time->schedules();
        $hours = $this->time->hoursByDay($ids, $from, $to);
        $sheets = [];
        foreach ($this->time->forEmployees($ids, $from, $to) as $t) {
            $sheets[$t->employee_id][$t->week_start->toDateString()] = $t;
        }
        $leaves = [];
        foreach ($this->leave->inRange($ids, $from, $to, [LeaveRequestStatus::Approved]) as $r) {
            $leaves[$r->employee_id][] = $r;
        }
        $holidays = [];

        $out = [];
        foreach ($list as $id => $employee) {
            $schedule = $this->scheduleOf($employee, $schedules->all());
            $branch = $employee->branch_id ?? 0;
            $holidays[$branch] ??= $this->settings->holidayDates($from, $to, $employee->branch_id);
            $leaveDays = $this->leaveDays($leaves[$id] ?? [], $schedule);
            for ($week = $from->copy(); $week->lte($to); $week->addWeek()) {
                $key = $week->toDateString();
                $sheet = $sheets[$id][$key] ?? null;
                $out[$id][$key] = [
                    'week_start' => $key,
                    'status' => $sheet?->status->value ?? 'draft',
                    'timesheet_id' => $sheet?->id,
                    'schedule' => $schedule->toArray(),
                ] + WeekCalculator::week($week->copy(), $schedule, $hours[$id] ?? [], $leaveDays, $holidays[$branch]);
            }
        }

        return $out;
    }

    /**
     * One employee, one week.
     *
     * @return array{week_start: string, status: string, timesheet_id: int|null, schedule: array{days: list<int>, hours_per_day: float, source: string}, days: list<array<string, mixed>>, expected: float, worked: float, overtime: float, missing: float, absence: float}
     */
    public function week(Employee $employee, Carbon $weekStart): array
    {
        $start = WeekCalculator::weekStart($weekStart);

        return $this->summaries([$employee], $start, $start)[$employee->id][$start->toDateString()];
    }

    /** Refreshes the stored totals of a timesheet (lists and reports read them). */
    public function refreshTotals(Timesheet $timesheet): void
    {
        $sum = $this->week($timesheet->employee, $timesheet->week_start);
        $this->time->update($timesheet, [
            'expected_hours' => $sum['expected'],
            'worked_hours' => $sum['worked'],
            'overtime_hours' => $sum['overtime'],
        ]);
    }

    /** @param  list<WorkSchedule>  $schedules */
    public function scheduleOf(Employee $employee, array $schedules): Schedule
    {
        $own = Schedule::fromArray($employee->work_schedule, 'employee');
        if ($own !== null) {
            return $own;
        }
        $company = null;
        foreach ($schedules as $s) {
            if ($s->branch_id !== null && $s->branch_id === $employee->branch_id) {
                return Schedule::fromArray(['days' => $s->days, 'hours_per_day' => $s->hours_per_day], 'branch') ?? Schedule::fallback();
            }
            if ($s->branch_id === null) {
                $company = $s;
            }
        }

        return $company === null ? Schedule::fallback()
            : (Schedule::fromArray(['days' => $company->days, 'hours_per_day' => $company->hours_per_day], 'company') ?? Schedule::fallback());
    }

    /**
     * @param  list<LeaveRequest>  $requests
     * @return array<string, array{type: string, fraction: float}>
     */
    private function leaveDays(array $requests, Schedule $schedule): array
    {
        $out = [];
        foreach ($requests as $r) {
            $single = $r->starts_on->isSameDay($r->ends_on);
            for ($d = $r->starts_on->copy(); $d->lte($r->ends_on); $d->addDay()) {
                $fraction = 1.0;
                if ($single && $r->leaveType->unit === LeaveUnit::Hours && $schedule->hoursPerDay > 0) {
                    $fraction = min(1.0, $r->daysValue() / $schedule->hoursPerDay);
                } elseif (($r->half_day === HalfDay::Start && $d->isSameDay($r->starts_on)) || ($r->half_day === HalfDay::End && $d->isSameDay($r->ends_on))) {
                    $fraction = 0.5;
                }
                $date = $d->toDateString();
                $prev = $out[$date]['fraction'] ?? 0.0;
                $out[$date] = ['type' => $r->leaveType->name, 'fraction' => min(1.0, $prev + $fraction)];
            }
        }

        return $out;
    }
}
