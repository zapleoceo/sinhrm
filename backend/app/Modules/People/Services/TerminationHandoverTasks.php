<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Modules\Core\Support\UserTime;
use App\Modules\People\Models\Employee;
use App\Modules\Scripts\Contracts\TaskScheduler;
use App\Modules\Scripts\DTO\NewTask;
use App\Modules\Scripts\Enums\TaskType;
use Psr\Log\LoggerInterface;

/**
 * Handover colleague of a termination → a task in "Мої задачі" (Scripts Contracts\TaskScheduler, type
 * exit_handover (tasks.type is string(16)), source workflows): "Прийняти справи: <name> звільнений з dd.mm.yyyy". Only the name and the
 * date — no reason. Created when the termination applies (at once or by people.terminations), once per termination
 * date (rule key people:handover:<fired_at> with the terminated employee — a repeat returns the stored task); closed
 * on cancel and on restore. Skipped when there is no colleague, the colleague has no login or has been terminated since.
 */
final readonly class TerminationHandoverTasks
{
    public const string RULE = 'people:handover:';

    public function __construct(
        private TaskScheduler $tasks,
        private LoggerInterface $log,
    ) {}

    public static function ruleKey(string $firedAt): string
    {
        return self::RULE.$firedAt;
    }

    public static function title(Employee $employee): string
    {
        return sprintf('Прийняти справи: %s звільнений з %s', $employee->full_name, $employee->fired_at?->format('d.m.Y') ?? '');
    }

    public function open(Employee $employee): void
    {
        // The column may have been set in this transaction after the relation was loaded: read it again.
        $colleague = $employee->unsetRelation('handoverTo')->handoverTo;
        if ($employee->fired_at === null || $colleague === null || $colleague->user_id === null || $colleague->isTerminated()) {
            return;
        }
        $this->tasks->schedule(new NewTask(
            assigneeId: $colleague->user_id,
            type: TaskType::TerminationHandover,
            title: self::title($employee),
            dueAt: UserTime::today(),
            ruleKey: self::ruleKey($employee->fired_at->toDateString()),
            employeeId: $employee->id,
        ));
        $this->log->info('people.handover_task', ['id' => $employee->id, 'assignee' => $colleague->user_id]);
    }

    /** $firedAt: the termination date the task was created for (read before it is cleared). */
    public function close(Employee $employee, ?string $firedAt): void
    {
        if ($employee->handover_to_employee_id !== null && $firedAt !== null) {
            $this->tasks->closeByRule($employee->id, self::ruleKey($firedAt));
        }
    }
}
