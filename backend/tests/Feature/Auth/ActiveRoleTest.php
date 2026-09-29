<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Audit\Models\AuditEntry;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Core\Services\ModuleAccess;
use App\Modules\Directory\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

/** "Працювати як": PUT /api/auth/active-role narrows every server-side check to one assigned role (auth.md). */
final class ActiveRoleTest extends TestCase
{
    use RecruitingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The SPA is a stateful Sanctum origin: its requests carry the session that keeps the choice.
        $this->withHeader('Origin', 'http://localhost');
    }

    public function test_superadmin_acting_as_recruiter_loses_admin_access_and_null_restores_it(): void
    {
        $user = $this->userWithRoles(UserRole::Superadmin, UserRole::Recruiter);
        $recruiterModules = app(ModuleAccess::class)->allowedKeys($this->userWithRoles(UserRole::Recruiter));

        $this->actingAs($user)->getJson('/api/users')->assertOk();

        $this->switchTo($user, 'recruiter')
            ->assertOk()
            ->assertJsonPath('roles', ['superadmin', 'recruiter'])
            ->assertJsonPath('active_role', 'recruiter')
            ->assertJsonPath('effective_roles', ['recruiter'])
            ->assertJsonPath('modules', $recruiterModules);

        $this->actingAs($user)->getJson('/api/users')->assertForbidden();
        $this->actingAs($user)->getJson('/api/auth/me')
            ->assertJsonPath('active_role', 'recruiter')
            ->assertJsonPath('modules', $recruiterModules);

        // The way back is always open, whatever role is active.
        $this->switchTo($user, null)->assertOk()->assertJsonPath('active_role', null)
            ->assertJsonPath('effective_roles', ['superadmin', 'recruiter']);
        $this->actingAs($user)->getJson('/api/users')->assertOk();
    }

    public function test_only_an_assigned_role_can_be_picked(): void
    {
        $user = $this->userWithRoles(UserRole::Admin, UserRole::Recruiter);

        $this->switchTo($user, 'superadmin')->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->switchTo($user, 'nobody')->assertUnprocessable();
        $this->actingAs($user)->putJson('/api/auth/active-role', [])->assertUnprocessable();

        $single = $this->userWithRoles(UserRole::Recruiter);
        $this->switchTo($single, 'recruiter')->assertUnprocessable();
        $this->switchTo($single, null)->assertOk()->assertJsonPath('active_role', null);
    }

    public function test_guest_gets_401(): void
    {
        $this->putJson('/api/auth/active-role', ['role' => null])->assertUnauthorized();
    }

    public function test_a_removed_role_silently_falls_back_to_all_roles(): void
    {
        $user = $this->userWithRoles(UserRole::Superadmin, UserRole::Recruiter);
        $this->switchTo($user, 'recruiter')->assertOk();

        $user->roles()->detach(Role::findByName('recruiter', 'web')->id);

        $this->actingAs($user)->getJson('/api/auth/me')
            ->assertJsonPath('roles', ['superadmin'])
            ->assertJsonPath('active_role', null)
            ->assertJsonPath('effective_roles', ['superadmin']);
        $this->actingAs($user)->getJson('/api/users')->assertOk();
    }

    public function test_a_stale_choice_is_forgotten_and_not_reapplied_after_reassignment(): void
    {
        $user = $this->userWithRoles(UserRole::Superadmin, UserRole::Recruiter);
        $this->switchTo($user, 'recruiter')->assertOk();

        $user->removeRole('recruiter');
        $this->actingAs($user)->getJson('/api/auth/me')->assertJsonPath('active_role', null);
        $user->assignRole('recruiter');

        $this->actingAs($user)->getJson('/api/auth/me')
            ->assertJsonPath('active_role', null)
            ->assertJsonPath('effective_roles', ['superadmin', 'recruiter']);
    }

    public function test_contextual_hiring_manager_role_keeps_working_as_employee(): void
    {
        $north = Branch::factory()->create();
        $user = $this->userWithRoles(UserRole::Admin, UserRole::Employee);
        $mine = $this->vacancyIn($north);
        $mine->forceFill(['hiring_manager_id' => $user->id])->save();
        $foreign = $this->vacancyIn($north);

        $this->switchTo($user, 'employee')->assertOk();

        $this->actingAs($user)->getJson("/api/vacancies/{$mine->id}")->assertOk();
        $this->actingAs($user)->getJson("/api/vacancies/{$foreign->id}")->assertForbidden();
    }

    public function test_last_superadmin_guard_and_audit_look_at_assigned_roles_and_record_the_acting_role(): void
    {
        $user = $this->userWithRoles(UserRole::Superadmin, UserRole::HrManager);
        $target = $this->userWithRoles(UserRole::Viewer);

        $this->switchTo($user, 'superadmin')->assertOk();
        $this->actingAs($user)->patchJson("/api/users/{$target->id}", ['role' => 'recruiter'])->assertOk();

        $entry = AuditEntry::query()->where('entity_type', 'user')->where('entity_id', $target->id)->latest('id')->firstOrFail();
        $this->assertSame($user->id, $entry->user_id);
        $this->assertSame('superadmin', $entry->meta['acting_role'] ?? null);

        // Users admin and the last-superadmin rule read ASSIGNED roles from the database, not the session choice.
        $this->switchTo($user, 'hr_manager')->assertOk();
        $this->assertSame(1, User::query()->role('superadmin')->count());
        $this->switchTo($user, null)->assertOk();
        $this->actingAs($user)->getJson('/api/users?q='.urlencode($user->email))
            ->assertOk()->assertJsonPath('data.0.roles', ['superadmin', 'hr_manager']);
    }

    /** @return TestResponse<Response> */
    private function switchTo(User $user, ?string $role): TestResponse
    {
        return $this->actingAs($user)->putJson('/api/auth/active-role', ['role' => $role]);
    }

    private function userWithRoles(UserRole ...$roles): User
    {
        $user = User::factory()->create();
        $user->assignRole(...UserRole::valuesOf($roles));

        return $user;
    }
}
