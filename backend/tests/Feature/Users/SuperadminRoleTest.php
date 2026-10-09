<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Models\User;
use App\Modules\Audit\Models\AuditEntry;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Auth\Enums\UserStatus;
use App\Modules\People\Models\Employee;
use App\Modules\Users\Exceptions\UserAdminException;
use App\Modules\Users\Services\UserAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PeopleFixtures;
use Tests\TestCase;

/**
 * HRM-84: a superadmin gives and takes the superadmin role of another user on «Адміністрування → Користувачі».
 * Only a superadmin may do it (gate manage-users), never on oneself, never leaving the system without an active
 * superadmin (a blocked one does not count); every change is audited with the role. Break-glass
 * (PeopleScope::isSoleAdministrator) follows the active superadmins/admins. Synthetic data only.
 */
final class SuperadminRoleTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    private User $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = User::factory()->withRole(UserRole::Superadmin)->create();
    }

    public function test_superadmin_grants_superadmin_and_the_change_is_audited(): void
    {
        $user = User::factory()->withRole(UserRole::Admin)->create();

        $this->actingAs($this->root)->patchJson("/api/users/{$user->id}", ['roles' => ['superadmin', 'admin']])
            ->assertOk()->assertJsonPath('data.roles', ['superadmin', 'admin']);

        $this->assertTrue($user->fresh()?->hasRole(UserRole::Superadmin->value));
        $this->assertEquals(['from' => 'admin', 'to' => 'superadmin, admin'], $this->lastRoleChange($user)->changes['role'] ?? null);
        $this->assertSame($this->root->id, $this->lastRoleChange($user)->user_id);
    }

    public function test_single_role_field_accepts_superadmin(): void
    {
        $user = User::factory()->withRole(UserRole::Viewer)->create();

        $this->actingAs($this->root)->patchJson("/api/users/{$user->id}", ['role' => 'superadmin'])
            ->assertOk()->assertJsonPath('data.roles', ['superadmin']);
    }

    public function test_superadmin_revokes_superadmin_of_another_and_it_is_audited(): void
    {
        $other = User::factory()->withRole(UserRole::Superadmin)->create();

        $this->actingAs($this->root)->patchJson("/api/users/{$other->id}", ['roles' => ['hr_manager']])
            ->assertOk()->assertJsonPath('data.roles', ['hr_manager']);

        $this->assertFalse($other->fresh()?->hasRole(UserRole::Superadmin->value));
        $this->assertEquals(['from' => 'superadmin', 'to' => 'hr_manager'], $this->lastRoleChange($other)->changes['role'] ?? null);
    }

    public function test_superadmin_invites_a_superadmin_and_it_is_audited(): void
    {
        $id = $this->actingAs($this->root)
            ->postJson('/api/users', ['email' => 'second.root@example.com', 'name' => 'Second Root', 'role' => 'superadmin'])
            ->assertCreated()->assertJsonPath('data.roles', ['superadmin'])->json('data.id');

        $entry = AuditEntry::query()->where('entity_type', 'user')->where('entity_id', $id)->where('action', 'role_changed')->sole();
        $this->assertEquals(['from' => null, 'to' => 'superadmin'], $entry->changes['role'] ?? null);
    }

    public function test_other_roles_get_403_and_nothing_changes(): void
    {
        $target = User::factory()->withRole(UserRole::Viewer)->create();
        $superadmin = User::factory()->withRole(UserRole::Superadmin)->create();

        foreach ([UserRole::Admin, UserRole::HrManager, UserRole::Recruiter, UserRole::Employee, UserRole::Viewer] as $role) {
            $actor = User::factory()->withRole($role)->create();
            $this->actingAs($actor)->patchJson("/api/users/{$target->id}", ['roles' => ['superadmin']])->assertForbidden();
            $this->actingAs($actor)->patchJson("/api/users/{$superadmin->id}", ['roles' => ['viewer']])->assertForbidden();
            $this->actingAs($actor)->postJson('/api/users', ['email' => "x.{$role->value}@example.com", 'name' => 'X Y', 'role' => 'superadmin'])
                ->assertForbidden();
        }

        $this->assertFalse($target->fresh()?->hasRole(UserRole::Superadmin->value));
        $this->assertTrue($superadmin->fresh()?->hasRole(UserRole::Superadmin->value));
        $this->assertSame(0, AuditEntry::query()->where('action', 'role_changed')->count());
    }

    public function test_cannot_change_own_superadmin_role(): void
    {
        $this->actingAs($this->root)->patchJson("/api/users/{$this->root->id}", ['roles' => ['admin']])
            ->assertUnprocessable()->assertJsonPath('code', 'self_change_forbidden');

        $this->assertTrue($this->root->fresh()?->hasRole(UserRole::Superadmin->value));
    }

    public function test_the_last_active_superadmin_never_loses_the_role(): void
    {
        // Over HTTP the actor is itself an active superadmin and cannot change itself, so the rule is a safety net for a
        // race: the actor was blocked by someone else meanwhile (its loaded copy is stale) and now demotes or blocks the
        // only remaining active superadmin. Checked against the real database through the service.
        $actor = User::factory()->withRole(UserRole::Superadmin)->create();
        User::query()->whereKey($actor->id)->update(['status' => UserStatus::Blocked->value]);
        $service = $this->app->make(UserAdminService::class);

        foreach ([[[UserRole::Viewer], null], [null, UserStatus::Blocked]] as [$roles, $status]) {
            try {
                $service->update($actor, $this->root, $roles, $status);
                $this->fail('last_superadmin expected');
            } catch (UserAdminException $e) {
                $this->assertSame('last_superadmin', $e->errorCode);
            }
        }
        $root = $this->root->fresh();
        $this->assertNotNull($root);
        $this->assertTrue($root->hasRole(UserRole::Superadmin->value));
        $this->assertTrue($root->isActive());
    }

    public function test_a_blocked_superadmin_does_not_count_as_active(): void
    {
        // A blocked superadmin is not a peer: "root" stays the only active superadmin.
        $blocked = User::factory()->withRole(UserRole::Superadmin)->blocked()->create();
        $other = User::factory()->withRole(UserRole::Superadmin)->create();

        // Two active superadmins: one may lose the role.
        $this->actingAs($this->root)->patchJson("/api/users/{$other->id}", ['roles' => ['viewer']])->assertOk();
        $this->assertSame(1, User::query()->role('superadmin')->where('status', 'active')->count());

        // The blocked superadmin may lose it too (it was not counted), and stays blocked.
        $this->actingAs($this->root)->patchJson("/api/users/{$blocked->id}", ['roles' => ['viewer']])
            ->assertOk()->assertJsonPath('data.status', 'blocked');
    }

    public function test_a_second_superadmin_turns_break_glass_off_and_blocking_turns_it_back_on(): void
    {
        $self = $this->employee(['full_name' => 'Owner Person'], $this->root);
        $this->assertBreakGlass($self, true);

        $peer = User::factory()->withRole(UserRole::Employee)->create();
        $this->actingAs($this->root)->patchJson("/api/users/{$peer->id}", ['roles' => ['superadmin']])->assertOk();
        $this->assertBreakGlass($self, false);

        // Blocked superadmins do not count: the owner is sole again.
        $this->actingAs($this->root)->patchJson("/api/users/{$peer->id}", ['status' => 'blocked'])->assertOk();
        $this->assertBreakGlass($self, true);

        // Unblocked and then demoted: off while the peer is active, on again once the role is taken.
        $this->actingAs($this->root)->patchJson("/api/users/{$peer->id}", ['status' => 'active'])->assertOk();
        $this->assertBreakGlass($self, false);
        $this->actingAs($this->root)->patchJson("/api/users/{$peer->id}", ['roles' => ['employee']])->assertOk();
        $this->assertBreakGlass($self, true);
    }

    private function assertBreakGlass(Employee $self, bool $expected): void
    {
        $this->actingAs($this->root)->getJson('/api/people/'.$self->id)
            ->assertOk()->assertJsonPath('data.access.decide', $expected);
    }

    private function lastRoleChange(User $user): AuditEntry
    {
        return AuditEntry::query()->where('entity_type', 'user')->where('entity_id', $user->id)
            ->where('action', 'role_changed')->latest('id')->firstOrFail();
    }
}
