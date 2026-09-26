<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Executors;

use App\Modules\Workflows\Contracts\StepExecutor;
use App\Modules\Workflows\Contracts\WorkflowTemplateRepository;
use App\Modules\Workflows\DTO\StepContext;
use App\Modules\Workflows\DTO\StepOutcome;
use App\Modules\Workflows\Enums\StepAction;
use App\Modules\Workflows\Exceptions\WorkflowException;
use App\Modules\Workflows\Models\WorkflowTemplate;
use App\Modules\Workflows\Services\WorkflowStarter;
use Illuminate\Validation\Rule;

/**
 * start_workflow: a nested run of another template for the same employee (anchor = the day the step runs).
 * Nesting deeper than WorkflowStarter::MAX_DEPTH (3) fails with "depth_limit" — this also stops loops (A → B → A).
 */
final readonly class StartWorkflowExecutor implements StepExecutor
{
    public function __construct(private WorkflowTemplateRepository $templates, private WorkflowStarter $starter) {}

    public function action(): StepAction
    {
        return StepAction::StartWorkflow;
    }

    public function configRules(): array
    {
        return ['template_id' => ['required', 'integer', Rule::exists(WorkflowTemplate::class, 'id')]];
    }

    public function execute(StepContext $context): StepOutcome
    {
        $template = $this->templates->find($context->step->int('template_id') ?? 0);
        if ($template === null) {
            return StepOutcome::failed('template_missing');
        }
        if (! $template->active) {
            return StepOutcome::skipped('template_inactive');
        }
        try {
            $run = $this->starter->start($template, $context->employee, $context->now->copy()->startOfDay(), null, null, $context->run);
        } catch (WorkflowException $e) {
            return StepOutcome::failed($e->errorCode);
        }

        return StepOutcome::done(['run_id' => $run?->id]);
    }
}
