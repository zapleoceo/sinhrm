<?php

declare(strict_types=1);

namespace App\Modules\People\Events;

use App\Modules\People\Models\Employee;

/**
 * A terminated employee was restored (POST /people/{id}/restore): working again, fired_at cleared.
 * Onboarding workflows are not started automatically (HR starts them by hand if needed). $firedAt (Y-m-d) is the
 * termination date that was undone (Workflows closes the handover task of that date).
 */
final readonly class EmployeeRestored
{
    public function __construct(public Employee $employee, public ?string $firedAt = null) {}
}
