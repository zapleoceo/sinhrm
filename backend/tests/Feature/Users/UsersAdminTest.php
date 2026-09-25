<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UsersAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superadmin = User::factory()->withRole(UserRole::Superadmin)->create(['name' => 'Aaron Root']);
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/users')->assertUnauthorized();
    }

    public function test_recruiter_and_admin_get_403(): void
    {
        foreach ([UserRole::Recruiter, UserRole::Admin] as $role) {
            $user = User::factory()->withRole($role)->create();
            $this->actingAs($user)->getJson('/api/users')->assertForbidden();
        }
    }

    public function test_list_is_paginated_and_accepts_per_page_as_string(): void
    {
        User::factory()->count(3)->withRole(UserRole::Viewer)->create();

        $this->actingAs($this->superadmin)->getJson('/api/users?perPage=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 4);
    }

    public function test_per_page_out_of_range_is_422(): void
    {
        $this->actingAs($this->superadmin)->getJson('/api/users?perPage=101')->assertUnprocessable();
        $this->actingAs($this->superadmin)->getJson('/api/users?perPage=abc')->assertUnprocessable();
    }

    public function test_list_filters_by_query_role_and_status(): void
    {
        User::factory()->withRole(UserRole::Recruiter)->create(['name' => 'Rita Recruiter', 'email' => 'rita@example.com']);
        User::factory()->withRole(UserRole::Viewer)->blocked()->create(['name' => 'Victor Viewer']);

        $this->actingAs($this->superadmin)->getJson('/api/users?q=RITA')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', 'rita@example.com');
        $this->actingAs($this->superadmin)->getJson('/api/users?role=viewer')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Victor Viewer');
        $this->actingAs($this->superadmin)->getJson('/api/users?status=blocked')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'blocked');
    }

    public function test_invite_creates_active_user_without_password(): void
    {
        $this->actingAs($this->superadmin)
            ->postJson('/api/users', ['email' => 'New.Person@Example.com', 'name' => 'New Person', 'role' => 'recruiter'])
            ->assertCreated()
            ->assertJsonPath('data.email', 'new.person@example.com')
            ->assertJsonPath('data.roles', ['recruiter'])
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.invited_by', $this->superadmin->id);

        $user = User::query()->where('email', 'new.person@example.com')->firstOrFail();
        $this->assertNull($user->getAttribute('password'));
    }

    public function test_invite_validation(): void
    {
        $this->actingAs($this->superadmin)->postJson('/api/users', ['email' => 'nope', 'name' => '', 'role' => 'superadmin'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'name', 'role']);
    }

    public function test_invite_existing_email_is_409(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($this->superadmin)
            ->postJson('/api/users', ['email' => 'TAKEN@example.com', 'name' => 'Dup', 'role' => 'viewer'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'email_taken');
    }

    public function test_update_changes_role_and_status(): void
    {
        $user = User::factory()->withRole(UserRole::Viewer)->create();

        $this->actingAs($this->superadmin)->patchJson("/api/users/{$user->id}", ['role' => 'admin', 'status' => 'blocked'])
            ->assertOk()
            ->assertJsonPath('data.roles', ['admin'])
            ->assertJsonPath('data.status', 'blocked');
    }

    public function test_update_validates_values(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->superadmin)->patchJson("/api/users/{$user->id}", ['role' => 'god', 'status' => 'gone'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role', 'status']);
    }

    public function test_superadmin_role_cannot_be_assigned_through_the_api(): void
    {
        $user = User::factory()->withRole(UserRole::Admin)->create();

        $this->actingAs($this->superadmin)->patchJson("/api/users/{$user->id}", ['role' => 'superadmin'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);

        $this->assertFalse($user->fresh()->hasRole(UserRole::Superadmin->value));
    }

    public function test_cannot_change_own_role_or_status(): void
    {
        $this->actingAs($this->superadmin)->patchJson("/api/users/{$this->superadmin->id}", ['status' => 'blocked'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'self_change_forbidden');
    }

    public function test_another_superadmin_can_be_demoted_while_the_actor_stays_active(): void
    {
        // Self-change is forbidden, so over HTTP the actor always remains an active superadmin;
        // the "last active superadmin" rule is a safety net covered in the UserAdminService unit test.
        $other = User::factory()->withRole(UserRole::Superadmin)->create();

        $this->actingAs($this->superadmin)->patchJson("/api/users/{$other->id}", ['role' => 'viewer'])
            ->assertOk()
            ->assertJsonPath('data.roles', ['viewer']);
    }

    public function test_branches_are_assigned_replaced_and_cleared(): void
    {
        $recruiter = User::factory()->withRole(UserRole::Recruiter)->create();
        $b = Branch::factory()->create(['name' => 'Branch B']);
        $a = Branch::factory()->create(['name' => 'Branch A']);

        $this->actingAs($this->superadmin)->patchJson("/api/users/{$recruiter->id}", ['branch_ids' => [$b->id, $a->id]])
            ->assertOk()
            ->assertJsonPath('data.branches', [
                ['id' => $a->id, 'name' => 'Branch A', 'status' => 'active'],
                ['id' => $b->id, 'name' => 'Branch B', 'status' => 'active'],
            ])
            ->assertJsonPath('data.roles', ['recruiter']);

        $this->actingAs($this->superadmin)->patchJson("/api/users/{$recruiter->id}", ['branch_ids' => [(string) $b->id]])
            ->assertOk()->assertJsonCount(1, 'data.branches');
        $this->actingAs($this->superadmin)->getJson('/api/users?q='.urlencode($recruiter->email))
            ->assertOk()->assertJsonPath('data.0.branches.0.id', $b->id);

        $this->actingAs($this->superadmin)->patchJson("/api/users/{$recruiter->id}", ['branch_ids' => []])
            ->assertOk()->assertJsonPath('data.branches', []);
        $this->assertDatabaseCount('branch_user', 0);

        // Not sent = unchanged.
        $this->actingAs($this->superadmin)->patchJson("/api/users/{$recruiter->id}", ['branch_ids' => [$a->id]])->assertOk();
        $this->actingAs($this->superadmin)->patchJson("/api/users/{$recruiter->id}", ['role' => 'viewer'])
            ->assertOk()->assertJsonPath('data.branches.0.id', $a->id);
    }

    public function test_branch_ids_are_validated(): void
    {
        $recruiter = User::factory()->withRole(UserRole::Recruiter)->create();
        $active = Branch::factory()->create();
        $disabled = Branch::factory()->disabled()->create();

        foreach ([[999999], [$disabled->id], ['abc'], [$active->id, $active->id], 'nope', null] as $ids) {
            $this->actingAs($this->superadmin)->patchJson("/api/users/{$recruiter->id}", ['branch_ids' => $ids])
                ->assertUnprocessable();
        }
        $this->assertDatabaseCount('branch_user', 0);
    }

    public function test_unknown_user_is_404(): void
    {
        $this->actingAs($this->superadmin)->patchJson('/api/users/999999', ['role' => 'viewer'])->assertNotFound();
    }
}
