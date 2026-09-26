<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Support;

use App\Modules\People\Models\Employee;
use App\Modules\Pulse\Models\SurveyWave;

/**
 * Who is asked in a wave (pure): a lifecycle wave — only its subject; otherwise every not terminated employee
 * matching the branch / department filter (an empty list = no restriction).
 */
final class WaveAudience
{
    public static function includes(SurveyWave $wave, Employee $employee): bool
    {
        if ($employee->isTerminated() && $wave->subject_employee_id !== $employee->id) {
            return false;
        }
        if ($wave->subject_employee_id !== null) {
            return $wave->subject_employee_id === $employee->id;
        }
        $branches = array_map('intval', $wave->audience['branch_ids'] ?? []);
        $departments = array_map('intval', $wave->audience['department_ids'] ?? []);

        return ($branches === [] || in_array($employee->branch_id, $branches, true))
            && ($departments === [] || in_array($employee->department_id, $departments, true));
    }
}
