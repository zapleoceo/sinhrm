<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Executors;

use App\Modules\Workflows\DTO\StepContext;
use App\Modules\Workflows\DTO\StepOutcome;
use App\Modules\Workflows\Enums\StepAction;

/** create_task: a task for the assignee (link — the employee profile); the step is done when the task is. */
final class CreateTaskExecutor extends TaskStepExecutor
{
    public function action(): StepAction
    {
        return StepAction::CreateTask;
    }

    public function configRules(): array
    {
        return ['title' => ['nullable', 'string', 'max:255']];
    }

    public function execute(StepContext $context): StepOutcome
    {
        return $this->assignTask($context, $this->title($context), self::profileLink($context->employee->id));
    }
}
