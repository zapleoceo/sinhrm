<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\People\Contracts\EmployeeRepository;
use App\Modules\People\DTO\PeopleContext;
use App\Modules\People\Models\Employee;
use App\Modules\People\Support\ReportingTree;

/**
 * Access to employee data (People and TimeOff). HR staff (superadmin, admin, hr_manager — UserRole::hrStaff()) manage everyone.
 * A manager is any user linked to an employee who has reports (direct or indirect) — the subtree follows manager_id.
 */
final readonly class PeopleScope
{
    public function __construct(private EmployeeRepository $employees) {}

    public function isAdmin(User $user): bool
    {
        return $user->isActive()
            && $user->hasAnyRole(UserRole::valuesOf(UserRole::hrStaff()));
    }

    public function employeeOf(User $user): ?Employee
    {
        return $this->employees->findByUser($user->id);
    }

    public function for(User $user): PeopleContext
    {
        if (! $user->isActive()) {
            return new PeopleContext($user->id, false, null, []);
        }
        $self = $this->employeeOf($user);
        $subtree = $self === null ? [] : ReportingTree::descendants($this->employees->managerMap(), $self->id);

        return new PeopleContext($user->id, $this->isAdmin($user), $self?->id, $subtree);
    }
}
