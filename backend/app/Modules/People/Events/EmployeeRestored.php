<?php

declare(strict_types=1);

namespace App\Modules\People\Events;

use App\Modules\People\Models\Employee;

/**
 * A terminated employee was restored (POST /people/{id}/restore): working again, fired_at cleared.
 * No module subscribes yet: onboarding workflows are not started automatically (HR starts them by hand if needed).
 */
final readonly class EmployeeRestored
{
    public function __construct(public Employee $employee) {}
}
