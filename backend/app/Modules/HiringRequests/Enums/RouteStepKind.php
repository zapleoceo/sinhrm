<?php

declare(strict_types=1);

namespace App\Modules\HiringRequests\Enums;

/** Who approves a route step. */
enum RouteStepKind: string
{
    /** The requester's manager (People: employee.manager → their login). Skipped when there is none. */
    case Manager = 'manager';
    /** Any active user with the role (e.g. admin = HR, or a "branch director" given the admin role). */
    case Role = 'role';
    /** One named user (e.g. the branch director). */
    case User = 'user';
}
