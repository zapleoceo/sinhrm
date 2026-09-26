<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Services;

use App\Modules\People\Contracts\EmployeeRepository;
use App\Modules\People\Models\Employee;
use App\Modules\Workflows\Contracts\WorkflowTemplateRepository;
use App\Modules\Workflows\Enums\WorkflowTrigger;
use App\Modules\Workflows\Models\WorkflowTemplate;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Automatic starts. Each active template with the trigger starts at most once per employee (unique
 * template + employee + trigger), so a repeated event or cron run never duplicates a run. A failing template is
 * logged and does not stop the others — and never breaks the hire / terminate request that raised the event.
 */
final readonly class WorkflowTriggers
{
    /** probation_end: runs start when hired_at + probation_days is within the last N days (tolerates cron gaps). */
    public const int PROBATION_WINDOW_DAYS = 7;

    public function __construct(
        private WorkflowTemplateRepository $templates,
        private WorkflowStarter $starter,
        private EmployeeRepository $employees,
        private LoggerInterface $log,
    ) {}

    public function employeeHired(Employee $employee): int
    {
        return $this->startAll(WorkflowTrigger::EmployeeHired, $employee, $employee->hired_at);
    }

    public function employeeTerminated(Employee $employee): int
    {
        return $this->startAll(WorkflowTrigger::EmployeeTerminated, $employee, $employee->fired_at ?? Carbon::today());
    }

    /** Called by the tick job. @return int runs started */
    public function probationEnded(Carbon $now): int
    {
        $templates = $this->templates->activeByTrigger(WorkflowTrigger::ProbationEnd);
        if ($templates->isEmpty()) {
            return 0;
        }
        $today = $now->copy()->startOfDay();
        $started = 0;
        foreach ($this->employees->working() as $employee) {
            foreach ($templates as $template) {
                $end = $employee->hired_at->copy()->startOfDay()->addDays($template->probation_days);
                if ($end->lte($today) && $end->gte($today->copy()->subDays(self::PROBATION_WINDOW_DAYS))) {
                    $started += $this->startOne($template, $employee, $end, WorkflowTrigger::ProbationEnd);
                }
            }
        }

        return $started;
    }

    /**
     * Idempotency key of one occurrence: "<trigger>:<anchor date>". A duplicate event for the same hire/termination
     * is ignored, a rehire (new hired_at) or a second termination starts the template again.
     */
    public static function occurrenceKey(WorkflowTrigger $trigger, Carbon $anchor): string
    {
        return $trigger->value.':'.$anchor->toDateString();
    }

    private function startAll(WorkflowTrigger $trigger, Employee $employee, Carbon $anchor): int
    {
        $started = 0;
        foreach ($this->templates->activeByTrigger($trigger) as $template) {
            $started += $this->startOne($template, $employee, $anchor, $trigger);
        }

        return $started;
    }

    private function startOne(WorkflowTemplate $template, Employee $employee, Carbon $anchor, WorkflowTrigger $trigger): int
    {
        try {
            return $this->starter->start($template, $employee, $anchor, null, self::occurrenceKey($trigger, $anchor)) === null ? 0 : 1;
        } catch (Throwable $e) {
            $this->log->error('workflows.trigger_failed', [
                'template' => $template->id, 'employee' => $employee->id, 'trigger' => $trigger->value, 'exception' => $e::class,
            ]);

            return 0;
        }
    }
}
