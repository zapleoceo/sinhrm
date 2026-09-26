<?php

declare(strict_types=1);

namespace App\Modules\People\Events;

use App\Modules\People\Models\Employee;

/**
 * A new employee record was created (manually or from a hired application). TimeOff grants the first accrual,
 * Workflows starts onboarding templates (anchor date = hired_at).
 */
final readonly class EmployeeHired
{
    public function __construct(public Employee $employee) {}
}
