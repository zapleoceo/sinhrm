<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Listeners;

use App\Modules\People\Events\EmployeeHired;
use App\Modules\Workflows\Services\WorkflowTriggers;

/** People → Workflows: a new employee (manual create or hire from Recruiting) starts "employee_hired" templates. */
final readonly class StartOnboardingWorkflows
{
    public function __construct(private WorkflowTriggers $triggers) {}

    public function handle(EmployeeHired $event): void
    {
        $this->triggers->employeeHired($event->employee);
    }
}
