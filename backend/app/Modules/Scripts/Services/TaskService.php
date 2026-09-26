<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Services;

use App\Models\User;
use App\Modules\Recruiting\Services\RecruitingScope;
use App\Modules\Scripts\Contracts\TaskRepository;
use App\Modules\Scripts\DTO\NewTask;
use App\Modules\Scripts\DTO\TaskFilter;
use App\Modules\Scripts\Enums\TaskType;
use App\Modules\Scripts\Events\TaskCompleted;
use App\Modules\Scripts\Models\Task;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * The unified task list: recruiter follow-ups (Recruiting scope) plus workflow and document tasks of employees
 * (assigned to a person). Listing, marking done/undone, creating tasks for other modules exactly once.
 */
final readonly class TaskService
{
    public const int LIMIT = 200;

    public const int NEW_APPLICANT_MINUTES = 60;

    public const string NEW_APPLICANT_RULE = 'mail:new_applicant';

    /** Stored title (fallback); the UI shows a translated label by type "new_applicant". */
    public const string NEW_APPLICANT_TITLE = 'Call the new applicant';

    public function __construct(
        private TaskRepository $tasks,
        private RecruitingScope $scope,
        private Dispatcher $events,
    ) {}

    /** @return Collection<int, Task> */
    public function list(User $actor, TaskFilter $filter, ?Carbon $now = null): Collection
    {
        return $this->tasks->list($this->scope->for($actor), $filter, $now ?? Carbon::now(), self::LIMIT);
    }

    public function canSee(User $actor, Task $task): bool
    {
        return $actor->isActive() && $this->tasks->isVisible($this->scope->for($actor), $task);
    }

    /**
     * The assignee may always close their own task (an employee with the viewer role completes onboarding tasks);
     * writers (not viewers) may close tasks they see. Closing twice keeps the first done_at.
     */
    public function canUpdate(User $actor, Task $task): bool
    {
        if (! $actor->isActive()) {
            return false;
        }

        return $task->assignee_id === $actor->id || ($this->scope->canWrite($actor) && $this->canSee($actor, $task));
    }

    /**
     * "Call the new applicant within 1 hour" for a fresh application (mail agent). Once per application
     * (unique application_id + rule_key), so a repeated sync never duplicates it.
     */
    public function scheduleNewApplicantCall(int $assigneeId, int $candidateId, int $applicationId, Carbon $receivedAt): bool
    {
        return $this->tasks->createFollowupOnce([
            'assignee_id' => $assigneeId,
            'candidate_id' => $candidateId,
            'application_id' => $applicationId,
            'type' => TaskType::NewApplicant->value,
            'title' => self::NEW_APPLICANT_TITLE,
            'due_at' => $receivedAt->copy()->addMinutes(self::NEW_APPLICANT_MINUTES),
            'rule_key' => self::NEW_APPLICANT_RULE,
        ]);
    }

    /** A workflow/document task, once per (employee, rule key); a repeat returns the stored task. */
    public function schedule(NewTask $task): Task
    {
        return $this->tasks->createOnce($task->attributes());
    }

    /** Closes the task of another module (step completed elsewhere, document acknowledged). No event. */
    public function closeByRule(int $employeeId, string $ruleKey, ?Carbon $at = null): void
    {
        $task = $this->tasks->findByRule($employeeId, $ruleKey);
        if ($task !== null) {
            $this->setDone($task, true, $at);
        }
    }

    /** A user ticks the task: marks it and tells the owning module (TaskCompleted) when it became done. */
    public function complete(User $actor, Task $task, bool $done): Task
    {
        // Atomic transition: TaskCompleted is dispatched exactly once even for concurrent clicks.
        if ($this->tasks->markDone($task, $done, Carbon::now()) && $done) {
            $this->events->dispatch(new TaskCompleted($task, $actor));
        }

        return $task;
    }

    public function setDone(Task $task, bool $done, ?Carbon $at = null): Task
    {
        $this->tasks->markDone($task, $done, $at ?? Carbon::now());

        return $task;
    }
}
