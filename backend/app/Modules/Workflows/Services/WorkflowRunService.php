<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Services;

use App\Models\User;
use App\Modules\People\DTO\PeopleContext;
use App\Modules\People\Models\Employee;
use App\Modules\Scripts\Services\TaskService;
use App\Modules\Workflows\Contracts\WorkflowRunRepository;
use App\Modules\Workflows\DTO\RunFilter;
use App\Modules\Workflows\DTO\StepOutcome;
use App\Modules\Workflows\Enums\RunStatus;
use App\Modules\Workflows\Enums\RunStepStatus;
use App\Modules\Workflows\Exceptions\WorkflowException;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Models\WorkflowRunStep;
use App\Modules\Workflows\Models\WorkflowTemplate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Runs as seen by people: listing (admin — all; a manager — runs of employees below them), manual start and
 * cancel (admin), complete / skip a step (its assignee or an admin), retry a failed step (admin).
 * The employee does not see runs; they see their tasks in "Мої задачі".
 */
final readonly class WorkflowRunService
{
    public const int LIMIT = 200;

    public const string TASK_RULE_PREFIX = 'wf:';

    public function __construct(
        private WorkflowRunRepository $runs,
        private WorkflowStarter $starter,
        private StepRunner $runner,
        private TaskService $tasks,
        private LoggerInterface $log,
    ) {}

    /** @return Collection<int, WorkflowRun> */
    public function list(PeopleContext $ctx, RunFilter $filter): Collection
    {
        if (! $ctx->admin) {
            $filter = $filter->restrictedTo($ctx->subtreeIds);
        }

        return $this->runs->list($filter, self::LIMIT);
    }

    public function canView(PeopleContext $ctx, WorkflowRun $run): bool
    {
        return $ctx->admin || $ctx->isAbove($run->employee_id);
    }

    /** @throws ModelNotFoundException<WorkflowRun> missing or not visible */
    public function findVisible(PeopleContext $ctx, int $id): WorkflowRun
    {
        $run = $this->runs->find($id);
        if ($run === null || ! $this->canView($ctx, $run)) {
            throw (new ModelNotFoundException)->setModel(WorkflowRun::class, [$id]);
        }

        return $run;
    }

    public function find(int $id): WorkflowRun
    {
        return $this->runs->find($id) ?? throw (new ModelNotFoundException)->setModel(WorkflowRun::class, [$id]);
    }

    /** Manual start from the employee profile (anchor = the chosen date, today by default). Always a new run. */
    public function start(User $actor, WorkflowTemplate $template, Employee $employee, ?Carbon $anchor = null): WorkflowRun
    {
        $run = $this->starter->start($template, $employee, $anchor ?? Carbon::today(), $actor);
        assert($run !== null);

        return $this->find($run->id);
    }

    /** @throws WorkflowException run_not_running */
    public function cancel(User $actor, WorkflowRun $run, ?Carbon $now = null): WorkflowRun
    {
        $now ??= Carbon::now();
        if ($run->status !== RunStatus::Running) {
            throw WorkflowException::runNotRunning();
        }
        $this->runs->transaction(function () use ($run, $now): void {
            foreach ($run->steps as $step) {
                if (! $step->status->isFinished()) {
                    $this->runs->updateStep($step, ['status' => RunStepStatus::Skipped->value, 'completed_at' => $now, 'result' => ['reason' => 'cancelled']]);
                    $this->closeTask($run, $step, $now);
                }
            }
            $this->runs->updateRun($run, ['status' => RunStatus::Cancelled->value, 'completed_at' => $now]);
        });
        $this->log->info('workflows.run_cancelled', ['run' => $run->id, 'by' => $actor->id]);

        return $this->find($run->id);
    }

    /** Admin, or the assignee of the step (also for tasks shown in "Мої задачі"). */
    public function canActOn(PeopleContext $ctx, User $actor, WorkflowRunStep $step): bool
    {
        return $ctx->admin || ($actor->isActive() && $step->assignee_id === $actor->id);
    }

    /** @throws WorkflowException run_not_running | step_not_open */
    public function complete(User $actor, WorkflowRunStep $step, ?Carbon $now = null): WorkflowRunStep
    {
        return $this->finish($actor, $step, RunStepStatus::Done, null, $now ?? Carbon::now());
    }

    /** @throws WorkflowException run_not_running | step_not_open */
    public function skip(User $actor, WorkflowRunStep $step, ?string $reason, ?Carbon $now = null): WorkflowRunStep
    {
        return $this->finish($actor, $step, RunStepStatus::Skipped, $reason ?? 'skipped_by_user', $now ?? Carbon::now());
    }

    /**
     * Runs a failed step again right away (not on the next tick): the admin sees the result immediately.
     *
     * @throws WorkflowException run_not_running | step_not_failed
     */
    public function retry(User $actor, WorkflowRunStep $step, ?Carbon $now = null): WorkflowRunStep
    {
        $now ??= Carbon::now();
        $this->assertRunning($step->run);
        if ($step->status !== RunStepStatus::Failed) {
            throw WorkflowException::stepNotFailed();
        }
        $this->runs->updateStep($step, ['status' => RunStepStatus::Pending->value, 'executed_at' => null, 'result' => null]);
        $this->log->info('workflows.step_retry', ['step' => $step->id, 'by' => $actor->id]);
        $outcome = $this->runner->execute($step, $now);

        return $outcome instanceof StepOutcome ? $step->refresh() : $step;
    }

    /** A "wf:<id>" task was ticked in the task list: the step is done (if it is still open). */
    public function completeFromTask(string $ruleKey, User $actor): void
    {
        if (! str_starts_with($ruleKey, self::TASK_RULE_PREFIX)) {
            return;
        }
        $id = substr($ruleKey, strlen(self::TASK_RULE_PREFIX));
        $step = ctype_digit($id) ? $this->runs->findStep((int) $id) : null;
        if ($step === null || $step->status !== RunStepStatus::Pending || $step->run->status !== RunStatus::Running) {
            return;
        }
        try {
            $this->finish($actor, $step, RunStepStatus::Done, null, Carbon::now());
        } catch (WorkflowException) {
            // Finished concurrently by someone else: nothing to do.
        }
    }

    private function finish(User $actor, WorkflowRunStep $step, RunStepStatus $status, ?string $reason, Carbon $now): WorkflowRunStep
    {
        $run = $step->run;
        $this->assertRunning($run);
        if ($step->status->isFinished()) {
            throw WorkflowException::stepNotOpen();
        }
        $result = $step->result ?? [];
        if ($reason !== null) {
            $result['reason'] = $reason;
        }
        // Atomic: two concurrent clicks (or task tick + button) finish the step once.
        if (! $this->runs->finishOpenStep($step, [
            'status' => $status->value,
            'completed_by' => $actor->id,
            'completed_at' => $now,
            'result' => $result === [] ? null : $result,
        ])) {
            throw WorkflowException::stepNotOpen();
        }
        $this->closeTask($run, $step, $now);
        $this->runner->finishIfComplete($run, $now);
        $this->log->info('workflows.step_'.$status->value, ['step' => $step->id, 'by' => $actor->id]);

        return $step;
    }

    private function closeTask(WorkflowRun $run, WorkflowRunStep $step, Carbon $now): void
    {
        $this->tasks->closeByRule($run->employee_id, self::TASK_RULE_PREFIX.$step->id, $now);
    }

    private function assertRunning(WorkflowRun $run): void
    {
        if ($run->status !== RunStatus::Running) {
            throw WorkflowException::runNotRunning();
        }
    }
}
