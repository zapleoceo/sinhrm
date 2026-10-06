<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Listeners;

use App\Modules\People\Events\EmployeeRestored;
use App\Modules\People\Events\EmployeeTerminated;
use App\Modules\People\Events\EmployeeTerminationCancelled;
use App\Modules\Workflows\Services\TerminationHandoverTasks;

/**
 * People → Workflows: the handover task "Прийняти справи" opens when a termination applies (skipped without a
 * colleague, when the colleague has no login or has been terminated since) and closes on cancel / restore.
 */
final readonly class HandoverOnTermination
{
    public function __construct(private TerminationHandoverTasks $tasks) {}

    public function terminated(EmployeeTerminated $event): void
    {
        $employee = $event->employee;
        $colleague = $employee->handoverTo;
        if ($employee->fired_at === null || $colleague === null || $colleague->user_id === null || $colleague->isTerminated()) {
            return;
        }
        $this->tasks->open($employee->id, $employee->full_name, $employee->fired_at, $colleague->user_id);
    }

    public function cancelled(EmployeeTerminationCancelled $event): void
    {
        $this->tasks->close($event->employee->id, $event->firedAt);
    }

    public function restored(EmployeeRestored $event): void
    {
        $this->tasks->close($event->employee->id, $event->firedAt);
    }
}
