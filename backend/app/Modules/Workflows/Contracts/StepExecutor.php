<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Contracts;

use App\Modules\Workflows\DTO\StepContext;
use App\Modules\Workflows\DTO\StepOutcome;
use App\Modules\Workflows\Enums\StepAction;

/**
 * One action of a workflow step (Open/Closed): a new action = a new class tagged in WorkflowsServiceProvider.
 * Executors must be idempotent per run step (tasks use the "wf:<run step id>" key) and must not log or return
 * secrets or payloads — only codes and ids.
 */
interface StepExecutor
{
    public function action(): StepAction;

    /**
     * Validation rules of the step's config (keys without the "config." prefix), applied when a template is saved.
     *
     * @return array<string, mixed>
     */
    public function configRules(): array;

    public function execute(StepContext $context): StepOutcome;
}
