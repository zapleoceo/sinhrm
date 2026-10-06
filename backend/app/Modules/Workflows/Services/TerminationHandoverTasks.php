<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Services;

use App\Modules\Scripts\Contracts\TaskScheduler;
use App\Modules\Scripts\DTO\NewTask;
use App\Modules\Scripts\Enums\TaskType;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Handover colleague of a termination (People employees.handover_to_employee_id) → a task in "Мої задачі" (Scripts
 * Contracts\TaskScheduler, type exit_handover — tasks.type is string(16) —, source workflows):
 * "Прийняти справи: <name> звільнений з dd.mm.yyyy". Only the name and the date — no reason.
 * Opened on People EmployeeTerminated (Listeners\HandoverOnTermination; a failing listener is re-sent by People),
 * once per termination date: rule key people:handover:<fired_at> with the terminated employee — a repeat returns
 * the stored task. Closed on EmployeeTerminationCancelled and EmployeeRestored (both carry the date).
 * Lives in Workflows (offboarding), not in People: Scripts already depends on People (Task → Employee).
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

    public static function title(string $fullName, Carbon $firedAt): string
    {
        return sprintf('Прийняти справи: %s звільнений з %s', $fullName, $firedAt->format('d.m.Y'));
    }

    public function open(int $employeeId, string $fullName, Carbon $firedAt, int $colleagueUserId): void
    {
        $this->tasks->schedule(new NewTask(
            assigneeId: $colleagueUserId,
            type: TaskType::TerminationHandover,
            title: self::title($fullName, $firedAt),
            dueAt: Carbon::now(),
            ruleKey: self::ruleKey($firedAt->toDateString()),
            employeeId: $employeeId,
        ));
        $this->log->info('workflows.handover_task', ['id' => $employeeId, 'assignee' => $colleagueUserId]);
    }

    /** $firedAt: the termination date the task was created for (the employee's own fired_at is already cleared). */
    public function close(int $employeeId, ?string $firedAt): void
    {
        if ($firedAt !== null) {
            $this->tasks->closeByRule($employeeId, self::ruleKey($firedAt));
        }
    }
}
