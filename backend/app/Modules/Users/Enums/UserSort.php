<?php

declare(strict_types=1);

namespace App\Modules\Users\Enums;

/**
 * Sortable columns of Administration → Users (GET /api/users?sort=…). A closed list: the value never reaches SQL as
 * text, the repository maps each case to its own ORDER BY. Roles and branches are many-valued — not sortable.
 */
enum UserSort: string
{
    case Name = 'name';
    case Status = 'status';
    case LastLogin = 'last_login';
}
