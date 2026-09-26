<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Data migration 2026_10_09_100001_standardize_roles: up is re-runnable, down is safe for role holders. */
final class StandardRolesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const string MIGRATION = 'app/Modules/Auth/Database/Migrations/2026_10_09_100001_standardize_roles.php';

    public function test_every_enum_role_exists_after_migrations(): void
    {
        foreach (UserRole::values() as $name) {
            $this->assertTrue(Role::query()->where('name', $name)->where('guard_name', 'web')->exists(), $name);
        }
    }

    public function test_up_gives_employee_to_users_without_role_and_keeps_existing_roles(): void
    {
        $recruiter = User::factory()->withRole(UserRole::Recruiter)->create();
        $bare = User::factory()->create();

        $migration = require base_path(self::MIGRATION);
        $migration->up();
        $migration->up(); // re-runnable
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertSame(['recruiter'], $recruiter->fresh()?->getRoleNames()->all());
        $this->assertSame(['employee'], $bare->fresh()?->getRoleNames()->all());
    }

    public function test_down_moves_hr_managers_to_admin_and_removes_new_roles(): void
    {
        $hr = User::factory()->withRole(UserRole::HrManager)->create();
        $employee = User::factory()->withRole(UserRole::Employee)->create();
        $viewer = User::factory()->withRole(UserRole::Viewer)->create();

        $migration = require base_path(self::MIGRATION);
        $migration->down();
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertSame(['admin'], $hr->fresh()?->getRoleNames()->all());
        $this->assertSame([], $employee->fresh()?->getRoleNames()->all());
        $this->assertSame(['viewer'], $viewer->fresh()?->getRoleNames()->all());
        $this->assertFalse(Role::query()->whereIn('name', ['hr_manager', 'employee'])->exists());
    }
}
