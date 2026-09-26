<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Events;

use App\Models\User;
use App\Modules\Scripts\Models\Task;

/** A task was marked done by a user (not by the system). Workflows closes the linked run step ("wf:<id>"). */
final readonly class TaskCompleted
{
    public function __construct(public Task $task, public User $actor) {}
}
