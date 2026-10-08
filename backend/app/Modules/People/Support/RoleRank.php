<?php

declare(strict_types=1);

namespace App\Modules\People\Support;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;

/**
 * Ordering of the global roles, used by People to stop privilege escalation through an employee record:
 * an employee record is fully controlled by HR staff (people-manage), so linking it to a login — or terminating it,
 * which blocks that login — must never reach a login more powerful than the one doing it.
 *
 * The rank of a user is their strongest role. Roles without power over other people (employee, viewer) share 0.
 * For the actor the roles currently in force are used ("Працювати як" narrows them, never widens), for the target
 * the roles really assigned: that is the power the record would carry.
 */
final readonly class RoleRank
{
    private const array RANKS = [
        UserRole::Superadmin->value => 4,
        UserRole::Admin->value => 3,
        UserRole::HrManager->value => 2,
        UserRole::Recruiter->value => 1,
    ];

    /** Strongest role really assigned to the user. */
    public static function of(User $user): int
    {
        return self::maxOf($user->assignedRoles());
    }

    /** Strongest role the user acts with right now (never above self::of). */
    public static function actingAs(User $user): int
    {
        return self::maxOf($user->effectiveRoles());
    }

    /** True when the target login is more powerful than the actor — the escalation we refuse. */
    public static function outranks(User $target, User $actor): bool
    {
        return self::of($target) > self::actingAs($actor);
    }

    /** @param  list<string>  $roles */
    private static function maxOf(array $roles): int
    {
        $rank = 0;
        foreach ($roles as $role) {
            $rank = max($rank, self::RANKS[$role] ?? 0);
        }

        return $rank;
    }
}
