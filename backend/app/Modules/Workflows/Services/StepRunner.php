<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Services;

use App\Modules\Workflows\Contracts\WorkflowRunRepository;
use App\Modules\Workflows\DTO\StepContext;
use App\Modules\Workflows\DTO\StepOutcome;
use App\Modules\Workflows\Enums\RunStatus;
use App\Modules\Workflows\Enums\RunStepStatus;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Models\WorkflowRunStep;
use App\Modules\Workflows\Support\ExecutorRegistry;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Executes due steps through their executors. A step is claimed first (executed_at set atomically), so two
 * overlapping cron calls never run it twice. An executor exception = step "failed" with the exception class name
 * only (messages may contain URLs or secrets); the run keeps going and the failed step waits for retry or skip.
 */
final readonly class StepRunner
{
    /** Steps per tick: one cron call stays well inside the serverless time limit. */
    public const int BATCH = 50;

    public function __construct(
        private WorkflowRunRepository $runs,
        private ExecutorRegistry $executors,
        private LoggerInterface $log,
    ) {}

    /** @return array{executed: int, done: int, waiting: int, skipped: int, failed: int} */
    public function runDue(Carbon $now): array
    {
        $counters = ['executed' => 0, 'done' => 0, 'waiting' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($this->runs->dueSteps($now, self::BATCH) as $step) {
            $outcome = $this->execute($step, $now);
            if ($outcome === null) {
                continue;
            }
            $counters['executed']++;
            $key = $outcome->waiting ? 'waiting' : $outcome->status->value;
            if (isset($counters[$key])) {
                $counters[$key]++;
            }
        }

        return $counters;
    }

    /** Runs one step now; null when it was not claimable (already taken, finished or the run is not running). */
    public function execute(WorkflowRunStep $step, Carbon $now): ?StepOutcome
    {
        $run = $step->run;
        if ($run->status !== RunStatus::Running || ! $this->runs->claim($step, $now)) {
            return null;
        }
        $snapshot = $step->snapshotStep();
        try {
            $outcome = $this->executors->for($snapshot->action)
                ->execute(new StepContext($run, $step, $snapshot, $run->employee, $now));
        } catch (Throwable $e) {
            $outcome = StepOutcome::failed('exception:'.class_basename($e));
        }

        $attributes = ['status' => $outcome->status->value, 'result' => $outcome->result];
        if ($outcome->status->isFinished()) {
            $attributes['completed_at'] = $now;
        }
        $this->runs->updateStep($step, $attributes);
        if ($outcome->status === RunStepStatus::Failed) {
            $this->log->warning('workflows.step_failed', [
                'run' => $run->id, 'step' => $step->id, 'action' => $snapshot->action->value,
                'error' => $outcome->result['error'] ?? null, 'attempt' => $step->attempts,
            ]);
        }
        $this->finishIfComplete($run, $now);

        return $outcome;
    }

    /** Every step done or skipped → the run is completed. */
    public function finishIfComplete(WorkflowRun $run, Carbon $now): void
    {
        if ($run->status === RunStatus::Running && $this->runs->unfinishedCount($run) === 0) {
            $this->runs->updateRun($run, ['status' => RunStatus::Completed->value, 'completed_at' => $now]);
            $this->log->info('workflows.run_completed', ['run' => $run->id]);
        }
    }
}
