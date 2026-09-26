<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Http\Resources;

use App\Models\User;
use App\Modules\People\DTO\PeopleContext;
use App\Modules\Workflows\Enums\RunStatus;
use App\Modules\Workflows\Enums\RunStepStatus;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Models\WorkflowRunStep;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A run with its steps (the snapshot, not the current template). Per step: can_complete (its assignee or an
 * admin, while open), can_retry (admin, failed). result carries codes and ids only.
 *
 * @mixin WorkflowRun
 */
final class WorkflowRunResource extends JsonResource
{
    private ?PeopleContext $context = null;

    private ?User $actor = null;

    public static function for(WorkflowRun $run, PeopleContext $context, User $actor): self
    {
        $resource = new self($run);
        $resource->context = $context;
        $resource->actor = $actor;

        return $resource;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $admin = $this->context !== null && $this->context->admin;
        $running = $this->status === RunStatus::Running;
        $finished = $this->steps->filter(static fn (WorkflowRunStep $s): bool => $s->status->isFinished())->count();

        return [
            'id' => $this->id,
            'template' => ['id' => $this->template_id, 'name' => $this->template_name],
            'employee' => ['id' => $this->employee->id, 'full_name' => $this->employee->full_name],
            'anchor_date' => $this->anchor_date->toDateString(),
            'status' => $this->status->value,
            'trigger' => $this->trigger_key ?? 'manual',
            'started_by' => $this->starter === null ? null : ['id' => $this->starter->id, 'name' => $this->starter->name],
            'parent_run_id' => $this->parent_run_id,
            'depth' => $this->depth,
            'created_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'progress' => ['finished' => $finished, 'total' => $this->steps->count()],
            'has_failed' => $this->steps->contains(static fn (WorkflowRunStep $s): bool => $s->status === RunStepStatus::Failed),
            'can_cancel' => $admin && $running,
            'steps' => $this->steps->map(function (WorkflowRunStep $s) use ($admin, $running): array {
                $snapshot = $s->snapshotStep();
                $open = $running && ! $s->status->isFinished();

                return [
                    'id' => $s->id,
                    'position' => $s->position,
                    'title' => $snapshot->title,
                    'action' => $snapshot->action->value,
                    'offset_days' => $snapshot->offsetDays,
                    'assignee_rule' => $snapshot->assigneeRule->value,
                    'assignee' => $s->assignee === null ? null : ['id' => $s->assignee->id, 'name' => $s->assignee->name],
                    'due_at' => $s->due_at->toIso8601String(),
                    'status' => $s->status->value,
                    'waiting' => $s->status === RunStepStatus::Pending && $s->executed_at !== null,
                    'executed_at' => $s->executed_at?->toIso8601String(),
                    'attempts' => $s->attempts,
                    'completed_by' => $s->completer === null ? null : ['id' => $s->completer->id, 'name' => $s->completer->name],
                    'completed_at' => $s->completed_at?->toIso8601String(),
                    'result' => $s->result === null ? null : (object) $s->result,
                    'can_complete' => $open && ($admin || ($this->actor !== null && $s->assignee_id === $this->actor->id)),
                    'can_retry' => $admin && $running && $s->status === RunStepStatus::Failed,
                ];
            })->values()->all(),
        ];
    }
}
