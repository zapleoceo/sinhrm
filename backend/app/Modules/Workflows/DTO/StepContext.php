<?php

declare(strict_types=1);

namespace App\Modules\Workflows\DTO;

use App\Modules\People\Models\Employee;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Models\WorkflowRunStep;
use Illuminate\Support\Carbon;

/** Everything an executor needs to run one due step. */
final readonly class StepContext
{
    public function __construct(
        public WorkflowRun $run,
        public WorkflowRunStep $runStep,
        public StepSnapshot $step,
        public Employee $employee,
        public Carbon $now,
    ) {}

    /** Idempotency key of tasks created for this step ("wf:<run step id>"). */
    public function taskRule(): string
    {
        return 'wf:'.$this->runStep->id;
    }
}
