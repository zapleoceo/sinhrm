<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Listeners;

use App\Modules\People\Events\EmployeeTerminated;
use App\Modules\Workflows\Services\WorkflowTriggers;

/**
 * People → Workflows: a terminated employee starts "employee_terminated" templates (anchor = fired_at). A template that
 * failed to start makes this listener throw (WorkflowTriggerFailed): People keeps the event pending and re-sends it.
 */
final readonly class StartOffboardingWorkflows
{
    public function __construct(private WorkflowTriggers $triggers) {}

    public function handle(EmployeeTerminated $event): void
    {
        $this->triggers->employeeTerminated($event->employee);
    }
}
