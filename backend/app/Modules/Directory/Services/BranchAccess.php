<?php

declare(strict_types=1);

namespace App\Modules\Directory\Services;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Contracts\AccessibleBranches;
use App\Modules\Directory\Contracts\DictionaryRepository;

/**
 * Branch scope of a user: superadmin/admin see every branch (null), recruiter/viewer only the active branches
 * assigned to them, a blocked user sees nothing.
 */
final class BranchAccess implements AccessibleBranches
{
    /** Roles that are not limited by branches. */
    private const array UNRESTRICTED_ROLES = [UserRole::Superadmin, UserRole::Admin];

    public function __construct(private readonly DictionaryRepository $dictionaries) {}

    public function for(User $user): ?array
    {
        if (! $user->isActive()) {
            return [];
        }
        foreach (self::UNRESTRICTED_ROLES as $role) {
            if ($user->hasRole($role->value)) {
                return null;
            }
        }

        return $this->dictionaries->activeBranchIdsOfUser($user->id);
    }
}
