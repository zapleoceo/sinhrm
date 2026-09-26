<?php

declare(strict_types=1);

namespace App\Modules\People\Events;

use App\Modules\People\Models\Employee;

/** An employee was terminated (fired_at is set). Workflows starts offboarding templates. */
final readonly class EmployeeTerminated
{
    public function __construct(public Employee $employee) {}
}
