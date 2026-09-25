<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Policies;

use App\Models\User;
use App\Modules\Scripts\Models\Task;
use App\Modules\Scripts\Services\TaskService;

final readonly class TaskPolicy
{
    public function __construct(private TaskService $tasks) {}

    public function view(User $user, Task $task): bool
    {
        return $this->tasks->canSee($user, $task);
    }

    public function update(User $user, Task $task): bool
    {
        return $this->tasks->canUpdate($user, $task);
    }
}
