<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Enums;

/**
 * What starts a template automatically. The anchor date of the run (offset_days count from it):
 * employee_hired → hired_at, employee_terminated → fired_at, probation_end → hired_at + probation_days,
 * manual → the date chosen at start (today by default).
 */
enum WorkflowTrigger: string
{
    case Manual = 'manual';
    case EmployeeHired = 'employee_hired';
    case EmployeeTerminated = 'employee_terminated';
    case ProbationEnd = 'probation_end';
}
