<?php

declare(strict_types=1);

namespace App\Modules\Time\Contracts;

use App\Modules\Time\Enums\TimesheetStatus;
use App\Modules\Time\Models\Timesheet;
use App\Modules\Time\Models\WorkSchedule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

interface TimeRepository
{
    public function find(int $id): ?Timesheet;

    public function findWeek(int $employeeId, Carbon $weekStart): ?Timesheet;

    /** Get or create the week's row (unique employee + week; a concurrent create is re-read). */
    public function firstOrCreateWeek(int $employeeId, Carbon $weekStart): Timesheet;

    /**
     * Timesheets of these employees for the weeks (for team views, reports, reminders).
     *
     * @param  list<int>  $employeeIds
     * @return Collection<int, Timesheet>
     */
    public function forEmployees(array $employeeIds, Carbon $fromWeek, Carbon $toWeek): Collection;

    /**
     * Hours per employee and day in [from, to].
     *
     * @param  list<int>  $employeeIds
     * @return array<int, array<string, float>> employee id => [Y-m-d => hours]
     */
    public function hoursByDay(array $employeeIds, Carbon $from, Carbon $to): array;

    /**
     * Submitted timesheets of these employees (null = everyone), oldest week first.
     *
     * @param  list<int>|null  $employeeIds
     * @return Collection<int, Timesheet>
     */
    public function submitted(?array $employeeIds, ?int $exceptEmployeeId, int $limit): Collection;

    /**
     * Replaces all entries of the week.
     *
     * @param  list<array{date: string, hours: float, project: string|null, category: string|null, note: string|null}>  $entries
     */
    public function replaceEntries(Timesheet $timesheet, array $entries): void;

    /** @param  array<string, mixed>  $attributes */
    public function update(Timesheet $timesheet, array $attributes): void;

    /**
     * Compare-and-set of the status.
     *
     * @param  list<TimesheetStatus>  $from
     * @param  array<string, mixed>  $attributes
     */
    public function transition(Timesheet $timesheet, array $from, array $attributes): bool;

    /** @return Collection<int, WorkSchedule> */
    public function schedules(): Collection;

    /** @param  list<int>  $days */
    public function saveSchedule(?int $branchId, array $days, float $hoursPerDay): WorkSchedule;

    public function deleteSchedule(int $branchId): void;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function transaction(callable $callback): mixed;
}
