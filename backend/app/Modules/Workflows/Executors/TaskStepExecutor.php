<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Executors;

use App\Modules\Scripts\DTO\NewTask;
use App\Modules\Scripts\Enums\TaskType;
use App\Modules\Scripts\Services\TaskService;
use App\Modules\Workflows\Contracts\StepExecutor;
use App\Modules\Workflows\DTO\StepContext;
use App\Modules\Workflows\DTO\StepOutcome;
use App\Modules\Workflows\Services\AssigneeResolver;

/**
 * Shared part of actions that hand work to a person: a task in the unified task list ("wf:<run step id>", once),
 * assigned to the resolved assignee or — when the rule has nobody (no login) — to HR. The step waits for the task.
 */
abstract class TaskStepExecutor implements StepExecutor
{
    public function __construct(protected readonly TaskService $tasks, protected readonly AssigneeResolver $assignees) {}

    public static function profileLink(int $employeeId, ?string $tab = null): string
    {
        return '/people/'.$employeeId.($tab === null ? '' : '?tab='.$tab);
    }

    protected function assignTask(StepContext $context, string $title, string $link, ?int $assigneeId = null, bool $wait = true): StepOutcome
    {
        $fallback = false;
        $assigneeId ??= $context->runStep->assignee_id;
        if ($assigneeId === null) {
            $assigneeId = $this->assignees->hr();
            $fallback = true;
        }
        if ($assigneeId === null) {
            return StepOutcome::failed('no_assignee');
        }
        $task = $this->tasks->schedule(new NewTask(
            assigneeId: $assigneeId,
            type: TaskType::Workflow,
            title: $title,
            dueAt: $context->runStep->due_at,
            ruleKey: $context->taskRule(),
            employeeId: $context->employee->id,
            link: $link,
        ));
        $result = ['task_id' => $task->id, 'assignee_id' => $assigneeId] + ($fallback ? ['assignee_fallback' => true] : []);

        return $wait ? StepOutcome::waiting($result) : StepOutcome::done($result);
    }

    protected function title(StepContext $context): string
    {
        return $context->step->string('title') ?? $context->step->title;
    }
}
