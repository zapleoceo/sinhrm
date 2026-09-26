<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Directory\Models\Position;
use App\Modules\People\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PeopleFixtures;
use Tests\TestCase;

/** Synthetic data only: the repository is public. */
final class PeopleApiTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    public function test_guest_gets_401_everywhere(): void
    {
        $this->getJson('/api/people')->assertUnauthorized();
        $this->getJson('/api/people/1')->assertUnauthorized();
        $this->postJson('/api/people', [])->assertUnauthorized();
        $this->getJson('/api/people/org-chart')->assertUnauthorized();
        $this->getJson('/api/me/employee')->assertUnauthorized();
        $this->getJson('/api/people/change-requests')->assertUnauthorized();
        $this->postJson('/api/applications/1/hire')->assertUnauthorized();
    }

    public function test_blocked_user_gets_403(): void
    {
        $blocked = User::factory()->blocked()->withRole(UserRole::Admin)->create();

        $this->actingAs($blocked)->getJson('/api/people')->assertForbidden();
    }

    public function test_directory_is_open_to_every_active_user_without_pii(): void
    {
        $org = $this->org();
        $viewer = $this->login(UserRole::Viewer);

        $response = $this->actingAs($viewer)->getJson('/api/people?perPage=2')->assertOk()
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('data.0.full_name', 'Head Person');
        $row = $response->json('data.0');
        $this->assertIsArray($row);
        foreach (['birth_date', 'personal_email', 'hired_at', 'custom_fields', 'access'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $row);
        }
        $this->assertSame(['id', 'full_name', 'avatar_url', 'work_email', 'phone', 'status', 'branch', 'department', 'position', 'manager'], array_keys($row));
        $this->actingAs($viewer)->getJson('/api/people?manager_id='.$org['lead']->id)->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_directory_filters_and_validation(): void
    {
        $branch = Branch::factory()->create();
        $position = Position::factory()->create();
        $this->employee(['full_name' => 'Alpha Tester', 'branch_id' => $branch->id, 'position_id' => $position->id]);
        $this->employee(['full_name' => 'Beta Person']);
        Employee::factory()->terminated()->create(['full_name' => 'Gone Person']);
        $admin = $this->login(UserRole::Admin);

        $this->actingAs($admin)->getJson('/api/people')->assertOk()->assertJsonPath('meta.total', 2);
        $this->actingAs($admin)->getJson('/api/people?q=ALPHA')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($admin)->getJson('/api/people?branch_id='.$branch->id)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.branch.id', $branch->id);
        $this->actingAs($admin)->getJson('/api/people?position_id='.$position->id)->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($admin)->getJson('/api/people?status=terminated')->assertOk()->assertJsonPath('data.0.full_name', 'Gone Person');
        foreach (['perPage=0', 'perPage=abc', 'perPage=201', 'status=fired', 'branch_id=x'] as $q) {
            $this->actingAs($admin)->getJson("/api/people?$q")->assertUnprocessable();
        }
    }

    public function test_profile_visibility_matrix(): void
    {
        $org = $this->org();
        $worker = $org['worker'];
        $url = '/api/people/'.$worker->id;

        // admin: everything
        $this->actingAs($this->login(UserRole::Admin))->getJson($url)->assertOk()
            ->assertJsonPath('data.birth_date', '1990-05-01')
            ->assertJsonPath('data.hired_at', '2025-01-15')
            ->assertJsonPath('data.access', ['job' => true, 'pii' => true, 'decide' => true, 'manage' => true, 'self' => false]);
        // self: PII and job, cannot decide own requests
        $this->actingAs($this->userOf($worker))->getJson($url)->assertOk()
            ->assertJsonPath('data.personal_email', 'worker.home@example.test')
            ->assertJsonPath('data.access.self', true)
            ->assertJsonPath('data.access.decide', false);
        // managers above (direct and indirect): job data, no PII
        foreach ([$org['lead'], $org['head']] as $manager) {
            $data = $this->actingAs($this->userOf($manager))->getJson($url)->assertOk()
                ->assertJsonPath('data.hired_at', '2025-01-15')
                ->assertJsonPath('data.access.decide', true)
                ->json('data');
            $this->assertIsArray($data);
            $this->assertArrayNotHasKey('birth_date', $data);
            $this->assertArrayNotHasKey('personal_email', $data);
        }
        // peer, unrelated employee, viewer without an employee: directory tier only
        foreach ([$this->userOf($org['peer']), $this->userOf($org['other']), $this->login(UserRole::Viewer)] as $user) {
            $data = $this->actingAs($user)->getJson($url)->assertOk()->assertJsonPath('data.full_name', 'Worker Person')->json('data');
            $this->assertIsArray($data);
            foreach (['hired_at', 'birth_date', 'personal_email', 'employment_type', 'custom_fields'] as $hidden) {
                $this->assertArrayNotHasKey($hidden, $data);
            }
        }
        // a report never sees the manager's job data
        $data = $this->actingAs($this->userOf($worker))->getJson('/api/people/'.$org['lead']->id)->assertOk()->json('data');
        $this->assertIsArray($data);
        $this->assertArrayNotHasKey('hired_at', $data);
        $this->actingAs($this->userOf($worker))->getJson('/api/people/999999')->assertNotFound();
    }

    public function test_admin_creates_updates_and_terminates(): void
    {
        $admin = $this->login(UserRole::Admin);
        $branch = Branch::factory()->create();
        $user = $this->login(UserRole::Viewer);

        $id = $this->actingAs($admin)->postJson('/api/people', [
            'full_name' => '  New Hire ',
            'hired_at' => '2026-09-01',
            'work_email' => 'New.Hire@Example.test',
            'branch_id' => (string) $branch->id,
            'user_id' => $user->id,
            'employment_type' => 'part_time',
            'custom_fields' => ['shirt_size' => 'M'],
            'work_schedule' => ['days' => [1, 2, 3], 'hours_per_day' => 6],
        ])->assertCreated()
            ->assertJsonPath('data.full_name', 'New Hire')
            ->assertJsonPath('data.work_email', 'new.hire@example.test')
            ->assertJsonPath('data.branch.id', $branch->id)
            ->assertJsonPath('data.employment_type', 'part_time')
            ->assertJsonPath('data.custom_fields.shirt_size', 'M')
            ->assertJsonPath('data.status', 'active')
            ->json('data.id');
        $this->assertIsInt($id);

        $this->actingAs($admin)->postJson('/api/people', ['full_name' => 'Dup', 'hired_at' => '2026-09-01', 'user_id' => $user->id])
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->actingAs($admin)->postJson('/api/people', ['full_name' => 'X'])->assertUnprocessable()->assertJsonValidationErrors('hired_at');
        $this->actingAs($admin)->postJson('/api/people', ['full_name' => 'X', 'hired_at' => '2026-01-01', 'status' => 'terminated'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->actingAs($admin)->patchJson("/api/people/$id", ['phone' => '+380501112233', 'status' => 'on_leave'])->assertOk()
            ->assertJsonPath('data.phone', '+380501112233')->assertJsonPath('data.status', 'on_leave');

        $this->actingAs($admin)->postJson("/api/people/$id/terminate", ['fired_at' => '2026-12-31', 'reason' => 'Contract ended'])->assertOk()
            ->assertJsonPath('data.status', 'terminated')
            ->assertJsonPath('data.fired_at', '2026-12-31')
            ->assertJsonPath('data.termination_reason', 'Contract ended');
        $this->actingAs($admin)->postJson("/api/people/$id/terminate", ['fired_at' => '2026-12-31'])->assertStatus(409)
            ->assertJsonPath('code', 'already_terminated');
        $this->actingAs($admin)->postJson("/api/people/$id/terminate", [])->assertUnprocessable();
        $this->actingAs($admin)->deleteJson("/api/people/$id")->assertStatus(405);
    }

    public function test_only_admins_write(): void
    {
        $org = $this->org();
        foreach ([$this->userOf($org['head']), $this->userOf($org['worker']), $this->login(UserRole::Viewer)] as $user) {
            $this->actingAs($user)->postJson('/api/people', ['full_name' => 'X', 'hired_at' => '2026-01-01'])->assertForbidden();
            $this->actingAs($user)->patchJson('/api/people/'.$org['worker']->id, ['phone' => '1'])->assertForbidden();
            $this->actingAs($user)->postJson('/api/people/'.$org['worker']->id.'/terminate', ['fired_at' => '2026-01-01'])->assertForbidden();
        }
    }

    public function test_manager_cycle_is_rejected(): void
    {
        $org = $this->org();
        $admin = $this->login(UserRole::Admin);

        $this->actingAs($admin)->patchJson('/api/people/'.$org['head']->id, ['manager_id' => $org['worker']->id])
            ->assertUnprocessable()->assertJsonPath('code', 'manager_cycle');
        $this->actingAs($admin)->patchJson('/api/people/'.$org['head']->id, ['manager_id' => $org['head']->id])
            ->assertUnprocessable()->assertJsonPath('code', 'manager_cycle');
        $this->actingAs($admin)->patchJson('/api/people/'.$org['worker']->id, ['manager_id' => $org['other']->id])->assertOk()
            ->assertJsonPath('data.manager.id', $org['other']->id);
    }

    public function test_org_chart_tree_and_subtree(): void
    {
        $org = $this->org();
        Employee::factory()->terminated()->create(['manager_id' => $org['head']->id]);
        $viewer = $this->login(UserRole::Viewer);

        $roots = $this->actingAs($viewer)->getJson('/api/people/org-chart')->assertOk()->json('data');
        $this->assertIsArray($roots);
        $this->assertSame(['Head Person', 'Other Person'], array_column($roots, 'full_name'));
        $this->assertSame(1, $roots[0]['reports_count']);
        $this->assertSame(['Peer Person', 'Worker Person'], array_column($roots[0]['reports'][0]['reports'], 'full_name'));
        $this->assertArrayNotHasKey('birth_date', $roots[0]['reports'][0]['reports'][1]);

        $this->actingAs($this->userOf($org['lead']))->getJson('/api/people/org-chart?mine=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.full_name', 'Lead Person')->assertJsonCount(2, 'data.0.reports');
        $this->actingAs($viewer)->getJson('/api/people/org-chart?mine=1')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($viewer)->getJson('/api/people/org-chart?root_id='.$org['worker']->id)->assertOk()
            ->assertJsonPath('data.0.full_name', 'Worker Person');
    }

    public function test_me_employee(): void
    {
        $org = $this->org();

        $this->actingAs($this->login(UserRole::Viewer))->getJson('/api/me/employee')->assertNotFound()->assertJsonPath('code', 'no_employee');
        $this->actingAs($this->userOf($org['worker']))->getJson('/api/me/employee')->assertOk()
            ->assertJsonPath('data.id', $org['worker']->id)
            ->assertJsonPath('data.birth_date', '1990-05-01')
            ->assertJsonPath('data.manager.id', $org['lead']->id);
    }
}
