<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Enums;

/**
 * Who does a step (resolved to a user when the run starts, Services/AssigneeResolver):
 * employee — the employee's login; manager — the manager's login; hr_admin — the admin who started the run,
 * otherwise the first active superadmin/admin; specific_user — assignee_user_id.
 */
enum AssigneeRule: string
{
    case Employee = 'employee';
    case Manager = 'manager';
    case HrAdmin = 'hr_admin';
    case SpecificUser = 'specific_user';
}
