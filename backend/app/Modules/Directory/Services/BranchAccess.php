<?php

declare(strict_types=1);

namespace App\Modules\Directory\Services;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Contracts\AccessibleBranches;
use App\Modules\Directory\Contracts\DictionaryRepository;

/**
 * Branch scope of a user: HR staff (superadmin/admin/hr_manager) see every branch (null), others only the active branches
 * assigned to them, a blocked user sees nothing.
 */
final class BranchAccess implements AccessibleBranches
{
    public function __construct(private readonly DictionaryRepository $dictionaries) {}

    public function for(User $user): ?array
    {
        if (! $user->isActive()) {
            return [];
        }
        if ($user->hasAnyRole(UserRole::valuesOf(UserRole::hrStaff()))) {
            return null;
        }

        return $this->dictionaries->activeBranchIdsOfUser($user->id);
    }
}
