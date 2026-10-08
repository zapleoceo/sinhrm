<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Executors;

use App\Modules\Workflows\DTO\StepContext;
use App\Modules\Workflows\DTO\StepOutcome;

/**
 * A task step whose only setting is an optional title (the step title when empty) and whose link is the employee
 * profile: create_task, assign_buddy; request_form adds its form URL on top.
 */
abstract class ProfileTaskExecutor extends TaskStepExecutor
{
    public function configRules(): array
    {
        return ['title' => ['nullable', 'string', 'max:255']];
    }

    public function execute(StepContext $context): StepOutcome
    {
        return $this->assignTask($context, $this->title($context), self::profileLink($context->employee->id));
    }
}
