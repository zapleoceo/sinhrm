<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Models\User;
use App\Modules\Overview\Contracts\DashboardSection;
use App\Modules\People\Services\PeopleScope;
use App\Modules\Time\Models\Timesheet;
use App\Modules\Time\Support\WeekCalculator;
use Illuminate\Support\Carbon;

/**
 * Home page block "time": my current week {week_start, status, expected, worked, missing} (null without an employee
 * card) and timesheets waiting for my approval {count, items[≤5]}.
 */
final readonly class TimeDashboardSection implements DashboardSection
{
    public const int LIST = 5;

    public function __construct(
        private PeopleScope $scope,
        private WeekSummaryService $summaries,
        private TimesheetService $timesheets,
    ) {}

    public function key(): string
    {
        return 'time';
    }

    public function data(User $user, Carbon $now): array
    {
        $self = $this->scope->employeeOf($user);
        $week = null;
        if ($self !== null) {
            $s = $this->summaries->week($self, WeekCalculator::weekStart($now));
            $week = ['week_start' => $s['week_start'], 'status' => $s['status'], 'expected' => $s['expected'], 'worked' => $s['worked'], 'missing' => $s['missing']];
        }
        $approvals = $this->timesheets->approvals($user);

        return [
            'my_week' => $week,
            'my_approvals' => [
                'count' => $approvals->count(),
                'items' => array_values($approvals->take(self::LIST)->map(static fn (Timesheet $t): array => [
                    'id' => $t->id,
                    'employee' => ['id' => $t->employee->id, 'full_name' => $t->employee->full_name],
                    'week_start' => $t->week_start->toDateString(),
                    'worked' => (float) $t->worked_hours,
                    'overtime' => (float) $t->overtime_hours,
                ])->all()),
            ],
        ];
    }
}
