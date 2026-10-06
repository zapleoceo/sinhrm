<?php

declare(strict_types=1);

namespace App\Modules\People\Events;

use App\Modules\People\Models\Employee;

/**
 * A scheduled termination was cancelled before its date. $firedAt (Y-m-d) is the cancelled date — the employee's
 * fired_at is already null. Pulse removes (or, with answers, closes) the exit survey opened for that date.
 */
final readonly class EmployeeTerminationCancelled
{
    public function __construct(public Employee $employee, public string $firedAt) {}
}
