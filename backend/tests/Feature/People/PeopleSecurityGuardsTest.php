<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Auth\Enums\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PeopleFixtures;
use Tests\TestCase;

/**
 * Privilege-escalation and separation-of-duties guards of People. Synthetic data only: the repository is public.
 */
final class PeopleSecurityGuardsTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    /** An hr_manager must not attach a superadmin/admin login to an employee record they fully control. */
    public function test_linking_an_employee_to_a_higher_role_login_is_rejected(): void
    {
        $hr = $this->login(UserRole::HrManager);
        $superadmin = $this->login(UserRole::Superadmin);
        $admin = $this->login(UserRole::Admin);
        $employee = $this->employee(['full_name' => 'Plain Person']);

        foreach ([$superadmin, $admin] as $target) {
            $this->actingAs($hr)->patchJson('/api/people/'.$employee->id, ['user_id' => $target->id])
                ->assertUnprocessable()->assertJsonValidationErrors('user_id');
        }
        $this->assertNull($employee->refresh()->user_id);

        // Same or lower role stays allowed.
        $peer = $this->login(UserRole::HrManager);
        $this->actingAs($hr)->patchJson('/api/people/'.$employee->id, ['user_id' => $peer->id])->assertOk();
        $this->assertSame($peer->id, $employee->refresh()->user_id);
    }

    /** Creating an employee already linked to a higher-role login is the same escalation. */
    public function test_creating_an_employee_linked_to_a_higher_role_login_is_rejected(): void
    {
        $hr = $this->login(UserRole::HrManager);
        $superadmin = $this->login(UserRole::Superadmin);

        $this->actingAs($hr)->postJson('/api/people', [
            'full_name' => 'New Person', 'hired_at' => '2026-01-01', 'user_id' => $superadmin->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('user_id');
    }

    /** Terminating blocks the linked login: an hr_manager must not lock a superadmin out that way. */
    public function test_terminating_an_employee_linked_to_a_higher_role_login_is_forbidden(): void
    {
        $hr = $this->login(UserRole::HrManager);
        $superadmin = $this->login(UserRole::Superadmin);
        $employee = $this->employee(['full_name' => 'Owner Person'], $superadmin);

        $this->actingAs($hr)->postJson('/api/people/'.$employee->id.'/terminate', ['fired_at' => '2026-01-01'])
            ->assertForbidden()->assertJsonPath('code', 'forbidden');

        $this->assertFalse($employee->refresh()->isTerminated());
        $this->assertSame(UserStatus::Active, $superadmin->refresh()->status);

        // A superadmin may still terminate their peer's record.
        $this->actingAs($this->login(UserRole::Superadmin))
            ->postJson('/api/people/'.$employee->id.'/terminate', ['fired_at' => '2026-01-01'])->assertOk();
    }

    /** A manager sees the request of a subordinate, but never the PII values inside it. */
    public function test_change_request_hides_pii_values_from_a_manager(): void
    {
        $org = $this->org();
        $this->actingAs($this->userOf($org['worker']))->postJson('/api/me/employee/change-requests', [
            'changes' => [
                'phone' => '+380671112233',
                'personal_email' => 'worker.home@example.test',
                'address' => 'Secret street 1',
                'emergency_contact' => 'Mother, +380670000000',
            ],
        ])->assertCreated();

        $this->actingAs($this->userOf($org['lead']))->getJson('/api/people/change-requests')->assertOk()
            ->assertJsonPath('data.0.changes.phone', '+380671112233')
            ->assertJsonMissingPath('data.0.changes.personal_email')
            ->assertJsonMissingPath('data.0.changes.address')
            ->assertJsonMissingPath('data.0.changes.emergency_contact')
            ->assertJsonPath('data.0.hidden_changes', ['personal_email', 'address', 'emergency_contact']);

        $this->actingAs($this->login(UserRole::Admin))->getJson('/api/people/change-requests')->assertOk()
            ->assertJsonPath('data.0.changes.address', 'Secret street 1')
            ->assertJsonPath('data.0.hidden_changes', []);

        $this->actingAs($this->userOf($org['worker']))->getJson('/api/people/change-requests')->assertOk()
            ->assertJsonPath('data.0.changes.address', 'Secret street 1');
    }

    /** Separation of duties: nobody decides their own change request, HR staff included. */
    public function test_hr_cannot_decide_their_own_change_request(): void
    {
        $admin = $this->login(UserRole::Admin);
        $this->employee(['full_name' => 'Admin Person'], $admin);

        $id = $this->actingAs($admin)->postJson('/api/me/employee/change-requests', ['changes' => ['address' => 'New street 2']])
            ->assertCreated()->assertJsonPath('data.can_decide', false)->json('data.id');

        $this->actingAs($admin)->postJson('/api/people/change-requests/'.$id.'/approve')
            ->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->actingAs($admin)->postJson('/api/people/change-requests/'.$id.'/reject')
            ->assertForbidden()->assertJsonPath('code', 'forbidden');

        // Another admin decides it.
        $this->actingAs($this->login(UserRole::Admin))->postJson('/api/people/change-requests/'.$id.'/approve')->assertOk();
    }

    /** Separation of duties: HR does not write their own salary. */
    public function test_hr_cannot_add_compensation_to_their_own_record(): void
    {
        $admin = $this->login(UserRole::Admin);
        $self = $this->employee(['full_name' => 'Admin Person'], $admin);
        $other = $this->employee(['full_name' => 'Other Person']);
        $payload = ['amount' => 1000, 'currency' => 'UAH', 'period' => 'month', 'effective_on' => '2026-01-01'];

        $this->actingAs($admin)->postJson('/api/people/'.$self->id.'/compensation', $payload)
            ->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->actingAs($admin)->postJson('/api/people/'.$other->id.'/compensation', $payload)->assertCreated();
    }
}
