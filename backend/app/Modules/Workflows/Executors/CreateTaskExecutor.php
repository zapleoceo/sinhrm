<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Executors;

use App\Modules\Workflows\Enums\StepAction;

/** create_task: a task for the assignee (link — the employee profile); the step is done when the task is. */
final class CreateTaskExecutor extends ProfileTaskExecutor
{
    public function action(): StepAction
    {
        return StepAction::CreateTask;
    }
}
