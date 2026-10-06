<?php

declare(strict_types=1);

namespace App\Modules\People\Events;

use App\Modules\People\Models\Employee;

/**
 * A termination was scheduled for a future fired_at: the employee keeps working and logging in until the end of that
 * day (Kyiv). Pulse opens the exit survey now, while the person can still answer. Dispatched after the commit.
 */
final readonly class EmployeeTerminationScheduled
{
    public function __construct(public Employee $employee) {}
}
