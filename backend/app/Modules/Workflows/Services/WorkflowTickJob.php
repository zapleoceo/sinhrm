<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Services;

use App\Modules\Core\Contracts\ScheduledJob;
use Illuminate\Support\Carbon;

/**
 * "workflows.tick" for POST /api/ops/jobs/run (cron every ~30 min): starts probation_end runs, then executes due
 * steps (StepRunner::BATCH per call). Idempotent: runs are unique per trigger, steps are claimed before execution.
 */
final readonly class WorkflowTickJob implements ScheduledJob
{
    public function __construct(private WorkflowTriggers $triggers, private StepRunner $runner) {}

    public function name(): string
    {
        return 'workflows.tick';
    }

    public function run(Carbon $now): array
    {
        return ['probation_started' => $this->triggers->probationEnded($now)] + $this->runner->runDue($now);
    }
}
