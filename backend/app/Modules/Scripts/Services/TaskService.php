<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Services;

use App\Models\User;
use App\Modules\Recruiting\Services\RecruitingScope;
use App\Modules\Scripts\Contracts\TaskRepository;
use App\Modules\Scripts\DTO\TaskFilter;
use App\Modules\Scripts\Models\Task;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/** Recruiter tasks: listing within the Recruiting scope and marking done/undone. */
final readonly class TaskService
{
    public const int LIMIT = 200;

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

    public function setDone(Task $task, bool $done, ?Carbon $at = null): Task
    {
        if ($done === ($task->done_at !== null)) {
            return $task;
        }

        return $this->tasks->update($task, ['done_at' => $done ? ($at ?? Carbon::now()) : null]);
    }
}
