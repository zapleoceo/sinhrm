<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Executors;

use App\Modules\Workflows\DTO\StepContext;
use App\Modules\Workflows\DTO\StepOutcome;
use App\Modules\Workflows\Enums\StepAction;

/**
 * request_form: "fill in the form" task for the assignee. Link — the form's https URL (e.g. a Google Form) when
 * set, otherwise the employee profile. Forms of our own are a later module.
 */
final class RequestFormExecutor extends TaskStepExecutor
{
    public function action(): StepAction
    {
        return StepAction::RequestForm;
    }

    public function configRules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'url' => ['nullable', 'url:https', 'max:500'],
        ];
    }

    public function execute(StepContext $context): StepOutcome
    {
        $link = $context->step->string('url') ?? self::profileLink($context->employee->id);

        return $this->assignTask($context, $this->title($context), $link);
    }
}
