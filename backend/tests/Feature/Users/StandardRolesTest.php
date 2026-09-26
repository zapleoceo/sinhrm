<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\People\Services\PeopleScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The standard role set: hr_manager and employee can be invited/assigned; hr_manager acts as HR. */
final class StandardRolesTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superadmin = User::factory()->withRole(UserRole::Superadmin)->create();
    }

    public function test_new_roles_can_be_invited_and_assigned(): void
    {
        $this->actingAs($this->superadmin)
            ->postJson('/api/users', ['email' => 'hr.person@example.com', 'name' => 'Hr Person', 'role' => 'hr_manager'])
            ->assertCreated()
            ->assertJsonPath('data.roles', ['hr_manager']);

        $user = User::factory()->withRole(UserRole::Viewer)->create();
        $this->actingAs($this->superadmin)->patchJson("/api/users/{$user->id}", ['role' => 'employee'])
            ->assertOk()
            ->assertJsonPath('data.roles', ['employee']);
    }

    public function test_list_filters_by_new_role(): void
    {
        User::factory()->withRole(UserRole::HrManager)->create();
        User::factory()->withRole(UserRole::Employee)->create();

        $this->actingAs($this->superadmin)->getJson('/api/users?role=hr_manager')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.roles', ['hr_manager']);
    }

    public function test_hr_manager_may_be_safe_speak_handler_employee_may_not(): void
    {
        $hr = User::factory()->withRole(UserRole::HrManager)->create();
        $employee = User::factory()->withRole(UserRole::Employee)->create();

        $this->actingAs($this->superadmin)->patchJson("/api/users/{$hr->id}", ['safe_speak_handler' => true])
            ->assertOk()->assertJsonPath('data.safe_speak_handler', true);
        $this->actingAs($this->superadmin)->patchJson("/api/users/{$employee->id}", ['safe_speak_handler' => true])
            ->assertUnprocessable()->assertJsonPath('code', 'handler_requires_admin');
    }

    public function test_hr_manager_acts_as_hr_in_people_and_users_admin_stays_superadmin_only(): void
    {
        $hr = User::factory()->withRole(UserRole::HrManager)->create();
        $employee = User::factory()->withRole(UserRole::Employee)->create();
        $people = $this->app->make(PeopleScope::class);

        $this->assertTrue($people->isAdmin($hr));
        $this->assertFalse($people->isAdmin($employee));
        $this->actingAs($hr)->getJson('/api/users')->assertForbidden();
    }
}
