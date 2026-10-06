<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Services;

use App\Modules\Scripts\Contracts\TaskScheduler;
use App\Modules\Scripts\DTO\NewTask;
use App\Modules\Scripts\Enums\TaskType;
use App\Modules\TimeOff\Models\LeaveRequest;
use Psr\Log\LoggerInterface;

/**
 * Handover colleague of a leave request → a task in "Мої задачі" (Scripts Contracts\TaskScheduler, type leave_handover, source
 * timeoff): "Заміщення: <name> відсутній з dd.mm.yyyy по dd.mm.yyyy", due on the first day. Only the name and the
 * dates — no leave type, comment or balance. Created when the request is approved, once per request (rule key
 * timeoff:handover:<id> with the absent employee — a repeat returns the stored task); closed when the request is
 * rejected or cancelled. Skipped when there is no colleague, the colleague has no login or has been terminated since.
 */
final readonly class LeaveHandoverTasks
{
    public const string RULE = 'timeoff:handover:';

    public function __construct(
        private TaskScheduler $tasks,
        private LoggerInterface $log,
    ) {}

    public static function ruleKey(LeaveRequest $request): string
    {
        return self::RULE.$request->id;
    }

    public static function title(LeaveRequest $request): string
    {
        return sprintf('Заміщення: %s відсутній з %s по %s', $request->employee->full_name,
            $request->starts_on->format('d.m.Y'), $request->ends_on->format('d.m.Y'));
    }

    public function open(LeaveRequest $request): void
    {
        $colleague = $request->handoverTo;
        if ($colleague === null || $colleague->user_id === null || $colleague->isTerminated()) {
            return;
        }
        $this->tasks->schedule(new NewTask(
            assigneeId: $colleague->user_id,
            type: TaskType::LeaveHandover,
            title: self::title($request),
            dueAt: $request->starts_on->copy()->startOfDay(),
            ruleKey: self::ruleKey($request),
            employeeId: $request->employee_id,
        ));
        $this->log->info('timeoff.handover_task', ['id' => $request->id, 'assignee' => $colleague->user_id]);
    }

    public function close(LeaveRequest $request): void
    {
        if ($request->handover_to_employee_id !== null) {
            $this->tasks->closeByRule($request->employee_id, self::ruleKey($request));
        }
    }
}
