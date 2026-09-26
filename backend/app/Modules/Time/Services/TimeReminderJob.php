<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Core\Contracts\ScheduledJob;
use App\Modules\People\Contracts\EmployeeRepository;
use App\Modules\People\Models\Employee;
use App\Modules\Scripts\DTO\NewTask;
use App\Modules\Scripts\Enums\TaskType;
use App\Modules\Scripts\Services\TaskService;
use App\Modules\Time\Enums\TimesheetStatus;
use App\Modules\Time\Support\WeekCalculator;
use Illuminate\Support\Carbon;

/**
 * "time.reminders" for POST /api/ops/jobs/run. From Friday to Sunday (the cron may miss Friday), every working
 * employee with a login whose current week is not submitted/approved and still has missing hours (expected − worked
 * > 0; leave and holidays are not missing) gets the task "Заповніть табель за тиждень dd.mm". Idempotent per week:
 * the task key is time:reminder:<week start> per employee (unique in the task list). Submitting the week closes it.
 */
final readonly class TimeReminderJob implements ScheduledJob
{
    public const string RULE_PREFIX = 'time:reminder:';

    public function __construct(
        private EmployeeRepository $employees,
        private WeekSummaryService $summaries,
        private TaskService $tasks,
    ) {}

    public static function ruleKey(Carbon $weekStart): string
    {
        return self::RULE_PREFIX.$weekStart->toDateString();
    }

    public function name(): string
    {
        return 'time.reminders';
    }

    public function run(Carbon $now): array
    {
        if ($now->dayOfWeekIso < Carbon::FRIDAY) {
            return ['time_reminders' => 0, 'time_skipped' => 'not_friday'];
        }
        $start = WeekCalculator::weekStart($now);
        $people = $this->employees->working()->filter(static fn (Employee $e): bool => $e->user_id !== null);
        $sums = $this->summaries->summaries($people, $start, $start);
        $reminded = 0;
        foreach ($people as $e) {
            $s = $sums[$e->id][$start->toDateString()];
            $handedIn = TimesheetStatus::from($s['status'])->isHandedIn();
            if ($handedIn || $s['missing'] <= 0 || $e->user_id === null) {
                continue;
            }
            $this->tasks->schedule(new NewTask(
                assigneeId: $e->user_id,
                type: TaskType::TimesheetReminder,
                title: 'Заповніть табель за тиждень '.$start->format('d.m'),
                dueAt: $start->copy()->addDays(6)->endOfDay(),
                ruleKey: self::ruleKey($start),
                employeeId: $e->id,
                link: '/time?week='.$start->toDateString(),
            ));
            $reminded++;
        }

        return ['time_reminders' => $reminded];
    }
}
