<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Contracts;

use App\Modules\Workflows\DTO\RunFilter;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Models\WorkflowRunStep;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

interface WorkflowRunRepository
{
    /** @return Collection<int, WorkflowRun> newest first, with employee and steps */
    public function list(RunFilter $filter, int $limit): Collection;

    public function find(int $id): ?WorkflowRun;

    /**
     * Creates the run. With a trigger_key the insert is ignored when (template, employee, trigger_key) exists
     * (idempotent automatic starts, safe under races) and null is returned.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createRun(array $attributes): ?WorkflowRun;

    /** @param  list<array<string, mixed>>  $steps */
    public function createSteps(WorkflowRun $run, array $steps): void;

    public function findStep(int $id): ?WorkflowRunStep;

    /**
     * Pending, not yet executed steps of running runs that are due, oldest first.
     *
     * @return Collection<int, WorkflowRunStep> with run.employee
     */
    public function dueSteps(Carbon $now, int $limit): Collection;

    /** Marks the step as being executed; false when another process already took it (overlapping cron). */
    public function claim(WorkflowRunStep $step, Carbon $now): bool;

    /** @param  array<string, mixed>  $attributes */
    public function updateStep(WorkflowRunStep $step, array $attributes): WorkflowRunStep;

    /**
     * Atomically finishes an open (pending/failed) step: UPDATE ... WHERE status NOT IN (done, skipped).
     * False when another request finished it first.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function finishOpenStep(WorkflowRunStep $step, array $attributes): bool;

    /** @param  array<string, mixed>  $attributes */
    public function updateRun(WorkflowRun $run, array $attributes): WorkflowRun;

    /** Steps of the run that are neither done nor skipped. */
    public function unfinishedCount(WorkflowRun $run): int;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function transaction(callable $callback): mixed;
}
