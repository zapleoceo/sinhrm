<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Services;

use App\Models\User;
use App\Modules\People\Models\Employee;
use App\Modules\Workflows\Contracts\WorkflowRunRepository;
use App\Modules\Workflows\Enums\RunStatus;
use App\Modules\Workflows\Exceptions\WorkflowException;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Models\WorkflowStep;
use App\Modules\Workflows\Models\WorkflowTemplate;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Starts a run: freezes the template's steps into the run (snapshot — later template edits do not touch it),
 * computes due dates (anchor date 00:00 + offset_days) and resolves assignees. Nothing is executed here: due steps
 * run on the next "workflows.tick" (or right away through retry).
 */
final readonly class WorkflowStarter
{
    /** start_workflow may nest runs this deep (root = 0). */
    public const int MAX_DEPTH = 3;

    public function __construct(
        private WorkflowRunRepository $runs,
        private AssigneeResolver $assignees,
        private LoggerInterface $log,
    ) {}

    /**
     * @param  string|null  $triggerKey  automatic start: one run per (template, employee, trigger) — a repeat returns null
     *
     * @throws WorkflowException depth_limit
     */
    public function start(
        WorkflowTemplate $template,
        Employee $employee,
        Carbon $anchor,
        ?User $startedBy = null,
        ?string $triggerKey = null,
        ?WorkflowRun $parent = null,
    ): ?WorkflowRun {
        $depth = $parent === null ? 0 : $parent->depth + 1;
        if ($depth > self::MAX_DEPTH) {
            throw WorkflowException::depthLimit();
        }
        $template->loadMissing('steps');
        $employee->loadMissing('manager');

        $run = $this->runs->transaction(function () use ($template, $employee, $anchor, $startedBy, $triggerKey, $parent, $depth): ?WorkflowRun {
            $run = $this->runs->createRun([
                'template_id' => $template->id,
                'employee_id' => $employee->id,
                'template_name' => $template->name,
                'anchor_date' => $anchor->toDateString(),
                'started_by' => $startedBy?->id,
                'trigger_key' => $triggerKey,
                'parent_run_id' => $parent?->id,
                'depth' => $depth,
            ]);
            if ($run === null) {
                return null;
            }
            $day = $anchor->copy()->startOfDay();
            $this->runs->createSteps($run, array_values($template->steps->map(function (WorkflowStep $step) use ($employee, $startedBy, $day): array {
                $snapshot = $step->snapshot();

                return [
                    'step_id' => $step->id,
                    'position' => $step->position,
                    'snapshot' => $snapshot->toArray(),
                    'assignee_id' => $this->assignees->resolve($snapshot, $employee, $startedBy),
                    'due_at' => $day->copy()->addDays($snapshot->offsetDays),
                ];
            })->all()));
            if ($template->steps->isEmpty()) {
                $this->runs->updateRun($run, ['status' => RunStatus::Completed->value, 'completed_at' => Carbon::now()]);
            }

            return $run;
        });

        if ($run !== null) {
            $this->log->info('workflows.run_started', [
                'run' => $run->id, 'template' => $template->id, 'employee' => $employee->id,
                'trigger' => $triggerKey ?? 'manual', 'depth' => $depth,
            ]);
        }

        return $run;
    }
}
