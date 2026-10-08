<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Executors;

use App\Modules\Workflows\Enums\StepAction;

/**
 * assign_buddy: a task for the assignee (usually the manager) to pick a buddy for the new employee; link — the
 * profile. There is no "buddy" field yet: the choice is recorded by completing the task.
 */
final class AssignBuddyExecutor extends ProfileTaskExecutor
{
    public function action(): StepAction
    {
        return StepAction::AssignBuddy;
    }
}
