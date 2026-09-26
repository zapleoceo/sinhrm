<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Listeners;

use App\Modules\Scripts\Events\TaskCompleted;
use App\Modules\Workflows\Services\WorkflowRunService;

/** Scripts → Workflows: ticking a "wf:<run step id>" task in the task list completes that step. */
final readonly class CompleteStepFromTask
{
    public function __construct(private WorkflowRunService $runs) {}

    public function handle(TaskCompleted $event): void
    {
        if ($event->task->rule_key !== null) {
            $this->runs->completeFromTask($event->task->rule_key, $event->actor);
        }
    }
}
