<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Services;

use App\Models\User;
use App\Modules\Recruiting\Services\RecruitingScope;
use App\Modules\Scripts\Contracts\TaskRepository;
use App\Modules\Scripts\DTO\TaskFilter;
use App\Modules\Scripts\Enums\TaskType;
use App\Modules\Scripts\Models\Task;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/** Recruiter tasks: listing within the Recruiting scope and marking done/undone. */
final readonly class TaskService
{
    public const int LIMIT = 200;

    public const int NEW_APPLICANT_MINUTES = 60;

    public const string NEW_APPLICANT_RULE = 'mail:new_applicant';

    /** Stored title (fallback); the UI shows a translated label by type "new_applicant". */
    public const string NEW_APPLICANT_TITLE = 'Call the new applicant';

    public function __construct(private TaskRepository $tasks, private RecruitingScope $scope) {}

    /** @return Collection<int, Task> */
    public function list(User $actor, TaskFilter $filter, ?Carbon $now = null): Collection
    {
        return $this->tasks->list($this->scope->for($actor), $filter, $now ?? Carbon::now(), self::LIMIT);
    }

    public function canSee(User $actor, Task $task): bool
    {
        return $actor->isActive() && $this->tasks->isVisible($this->scope->for($actor), $task);
    }

    /** Writers (not viewers) may close tasks they see; closing twice keeps the first done_at. */
    public function canUpdate(User $actor, Task $task): bool
    {
        return $this->scope->canWrite($actor) && ($task->assignee_id === $actor->id || $this->canSee($actor, $task));
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

    public function setDone(Task $task, bool $done, ?Carbon $at = null): Task
    {
        if ($done === ($task->done_at !== null)) {
            return $task;
        }

        return $this->tasks->update($task, ['done_at' => $done ? ($at ?? Carbon::now()) : null]);
    }
}
