<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Executors;

use App\Modules\Workflows\DTO\StepContext;
use App\Modules\Workflows\DTO\StepOutcome;
use App\Modules\Workflows\Enums\StepAction;

/**
 * notify_manager: a task-notice for the employee's manager (whatever the assignee rule says). It does not block the
 * run — the step is done as soon as the notice exists. No manager with a login → skipped "no_manager".
 */
final class NotifyManagerExecutor extends TaskStepExecutor
{
    public function action(): StepAction
    {
        return StepAction::NotifyManager;
    }

    public function configRules(): array
    {
        return ['message' => ['nullable', 'string', 'max:255']];
    }

    public function execute(StepContext $context): StepOutcome
    {
        $managerUserId = $context->employee->manager?->user_id;
        if ($managerUserId === null) {
            return StepOutcome::skipped('no_manager');
        }
        $title = $context->step->string('message') ?? $context->step->title;

        return $this->assignTask($context, $title, self::profileLink($context->employee->id), $managerUserId, wait: false);
    }
}
