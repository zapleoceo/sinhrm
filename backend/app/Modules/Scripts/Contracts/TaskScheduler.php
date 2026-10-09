<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Contracts;

use App\Modules\Scripts\DTO\NewTask;
use App\Modules\Scripts\Models\Task;
use Illuminate\Support\Carbon;

/**
 * How another module creates and closes its tasks in "Мої задачі" without importing Scripts internals
 * (module boundaries). Implemented by Services\TaskService.
 */
interface TaskScheduler
{
    /** A task once per (employee, rule key); a repeat returns the stored task. */
    public function schedule(NewTask $task): Task;

    /** Closes the task of (employee, rule key), if any. */
    public function closeByRule(int $employeeId, string $ruleKey, ?Carbon $at = null): void;

    /** Closes every open task whose rule key starts with the prefix (e.g. all approver tasks of one route step). */
    public function closeByRulePrefix(string $prefix, ?Carbon $at = null): int;

    /**
     * "Call the new applicant within 1 hour" for a fresh application (mail agent, career page). Once per application;
     * false when it already existed.
     */
    public function scheduleNewApplicantCall(int $assigneeId, int $candidateId, int $applicationId, Carbon $receivedAt): bool;

    /** Marks the task done or open without the TaskCompleted event (the owning module closes its own task). */
    public function setDone(Task $task, bool $done, ?Carbon $at = null): Task;
}
