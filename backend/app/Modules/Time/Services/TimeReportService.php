<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\People\Contracts\EmployeeRepository;
use App\Modules\Time\Enums\TimesheetStatus;
use App\Modules\Time\Support\WeekCalculator;
use Illuminate\Support\Carbon;

/**
 * Week rows for the catalog reports (Reports module: time_by_employee, time_by_department, time_overtime,
 * time_missing). The range is widened to whole weeks (Monday of "from" … Sunday of "to") and capped at MAX_WEEKS
 * from the end. The caller passes the employees it may see (People scope).
 */
final readonly class TimeReportService
{
    public const int MAX_WEEKS = 26;

    public function __construct(private EmployeeRepository $employees, private WeekSummaryService $summaries) {}

    /**
     * @param  list<int>|null  $employeeIds  null = everyone
     * @return list<array{employee_id: int, employee: string, department: string|null, week_start: string, status: string, handed_in: bool, expected: float, worked: float, overtime: float, missing: float, absence: float}>
     */
    public function weekly(?array $employeeIds, Carbon $from, Carbon $to, ?int $branchId): array
    {
        if ($employeeIds === []) {
            return [];
        }
        $toWeek = WeekCalculator::weekStart($to);
        $fromWeek = WeekCalculator::weekStart($from)->max($toWeek->copy()->subWeeks(self::MAX_WEEKS - 1));
        $people = $this->employees->working($employeeIds, $branchId);
        $people->loadMissing('department:id,name');
        $sums = $this->summaries->summaries($people, $fromWeek, $toWeek);
        $rows = [];
        foreach ($people as $e) {
            foreach ($sums[$e->id] ?? [] as $week => $s) {
                if ($e->hired_at->gt(Carbon::parse($week)->addDays(6))) {
                    continue; // not yet employed that week
                }
                $rows[] = [
                    'employee_id' => $e->id,
                    'employee' => $e->full_name,
                    'department' => $e->department?->name,
                    'week_start' => $week,
                    'status' => $s['status'],
                    'handed_in' => TimesheetStatus::from($s['status'])->isHandedIn(),
                    'expected' => $s['expected'],
                    'worked' => $s['worked'],
                    'overtime' => $s['overtime'],
                    'missing' => $s['missing'],
                    'absence' => $s['absence'],
                ];
            }
        }

        return $rows;
    }
}
