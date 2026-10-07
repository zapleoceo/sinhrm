<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Exceptions;

use App\Modules\Workflows\Enums\WorkflowTrigger;
use RuntimeException;

/**
 * At least one template of an automatic trigger failed to start (already logged as workflows.trigger_failed).
 * Thrown for employee_terminated only, after every template was tried, so the People listener fails and
 * ScheduledTerminationJob re-sends EmployeeTerminated; templates that did start are not duplicated (trigger key).
 */
final class WorkflowTriggerFailed extends RuntimeException
{
    public static function for(WorkflowTrigger $trigger, int $employeeId, int $failed): self
    {
        return new self(sprintf('%d template(s) of trigger %s failed to start for employee %d', $failed, $trigger->value, $employeeId));
    }
}
