<?php

declare(strict_types=1);

namespace App\Modules\Directory\Contracts;

use App\Models\User;

/**
 * Branch scoping for data queries (candidates, vacancies of the future Recruiting module).
 *
 * Usage: $ids = $branches->for($user); if ($ids !== null) { $query->whereIn('branch_id', $ids); }
 */
interface AccessibleBranches
{
    /**
     * null = no restriction (active HR staff: superadmin/admin/hr_manager); otherwise the ids of the user's active branches
     * (an empty list = nothing is visible: a user without branches, or a blocked user).
     *
     * @return list<int>|null
     */
    public function for(User $user): ?array;
}
