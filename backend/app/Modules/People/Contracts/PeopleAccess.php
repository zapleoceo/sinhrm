<?php

declare(strict_types=1);

namespace App\Modules\People\Contracts;

use App\Models\User;
use App\Modules\People\DTO\PeopleContext;
use App\Modules\People\Models\Employee;

/**
 * Who the user is in People terms — for other modules (TimeOff, Time, Documents, Workflows, …). Implemented by
 * People\Services\PeopleScope: HR staff manage everyone, a manager sees the subtree of their reports.
 */
interface PeopleAccess
{
    /** HR staff (superadmin, admin, hr_manager) of an active account. */
    public function isAdmin(User $user): bool;

    /** The employee card linked to the user, if any. */
    public function employeeOf(User $user): ?Employee;

    /** Admin flag, own employee id and the reporting subtree; an inactive user gets an empty context. */
    public function for(User $user): PeopleContext;
}
