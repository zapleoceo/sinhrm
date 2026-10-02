<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\People\Services\PeopleScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Module gates defined through ModuleServiceProvider::defineRoleGate: an active user with one of the roles.
 * Pins who opens each gate, so moving the gates onto the shared helper changed nobody's access.
 */
final class RoleGateTest extends TestCase
{
    use RefreshDatabase;

    /** HR modules: superadmin, admin, hr_manager (UserRole::hrStaff(), the same set as PeopleScope::isAdmin). */
    private const array HR_STAFF_GATES = [
        'assets-manage', 'desk-manage', 'documents-manage', 'hiring-manage', 'knowledge-manage', 'people-manage',
        'perform-manage', 'pulse-manage', 'time-manage', 'timeoff-manage', 'workflows-manage',
    ];

    /** System administration: superadmin only. */
    private const array SUPERADMIN_GATES = ['view-audit-log', 'manage-modules', 'manage-integrations', 'manage-users'];

    /** Directory and Privacy: superadmin and admin (no hr_manager). */
    private const array ADMIN_GATES = ['manage-directory', 'privacy-manage'];

    public function test_each_gate_opens_exactly_for_its_roles(): void
    {
        $expected = [
            UserRole::Superadmin->value => [...self::HR_STAFF_GATES, ...self::SUPERADMIN_GATES, ...self::ADMIN_GATES],
            UserRole::Admin->value => [...self::HR_STAFF_GATES, ...self::ADMIN_GATES],
            UserRole::HrManager->value => self::HR_STAFF_GATES,
            UserRole::Recruiter->value => [],
            UserRole::Employee->value => [],
            UserRole::Viewer->value => [],
        ];
        $all = [...self::HR_STAFF_GATES, ...self::SUPERADMIN_GATES, ...self::ADMIN_GATES];

        foreach (UserRole::cases() as $role) {
            $user = User::factory()->withRole($role)->create();
            foreach ($all as $ability) {
                $this->assertSame(
                    in_array($ability, $expected[$role->value], true),
                    Gate::forUser($user)->allows($ability),
                    "{$role->value} → {$ability}",
                );
            }
        }
    }

    public function test_a_blocked_user_opens_no_gate(): void
    {
        $blocked = User::factory()->blocked()->withRole(UserRole::Superadmin)->create();

        foreach ([...self::HR_STAFF_GATES, ...self::SUPERADMIN_GATES, ...self::ADMIN_GATES] as $ability) {
            $this->assertFalse(Gate::forUser($blocked)->allows($ability), $ability);
        }
    }

    public function test_hr_gates_agree_with_people_scope(): void
    {
        $scope = $this->app->make(PeopleScope::class);
        $users = [
            ...array_map(static fn (UserRole $r): User => User::factory()->withRole($r)->create(), UserRole::cases()),
            User::factory()->blocked()->withRole(UserRole::Admin)->create(),
        ];

        foreach ($users as $user) {
            foreach (self::HR_STAFF_GATES as $ability) {
                $this->assertSame($scope->isAdmin($user), Gate::forUser($user)->allows($ability), $ability);
            }
        }
    }
}
