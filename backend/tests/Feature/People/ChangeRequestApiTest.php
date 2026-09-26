<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\People\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PeopleFixtures;
use Tests\TestCase;

/** Synthetic data only: the repository is public. */
final class ChangeRequestApiTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    public function test_submit_accepts_only_whitelisted_fields(): void
    {
        $org = $this->org();
        $worker = $this->userOf($org['worker']);

        $this->actingAs($worker)->postJson('/api/me/employee/change-requests', [
            'changes' => ['phone' => ' +380671112233 ', 'address' => 'Test street 1'],
            'comment' => 'Moved',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.changes.phone', '+380671112233')
            ->assertJsonPath('data.employee.id', $org['worker']->id)
            ->assertJsonPath('data.can_decide', false);

        foreach ([['hired_at' => '2020-01-01'], ['position_id' => 1], []] as $changes) {
            $this->actingAs($worker)->postJson('/api/me/employee/change-requests', ['changes' => $changes])->assertUnprocessable();
        }
        $this->actingAs($worker)->postJson('/api/me/employee/change-requests', ['changes' => ['personal_email' => 'not-an-email']])
            ->assertUnprocessable();
        $this->actingAs($this->login(UserRole::Viewer))->postJson('/api/me/employee/change-requests', ['changes' => ['phone' => '1']])
            ->assertNotFound()->assertJsonPath('code', 'no_employee');
    }

    public function test_list_is_scoped(): void
    {
        $org = $this->org();
        $this->actingAs($this->userOf($org['worker']))->postJson('/api/me/employee/change-requests', ['changes' => ['phone' => '1']])->assertCreated();
        $this->actingAs($this->userOf($org['other']))->postJson('/api/me/employee/change-requests', ['changes' => ['phone' => '2']])->assertCreated();

        $this->actingAs($this->login(UserRole::Admin))->getJson('/api/people/change-requests')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($this->userOf($org['lead']))->getJson('/api/people/change-requests?status=pending')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee.id', $org['worker']->id)->assertJsonPath('data.0.can_decide', true);
        $this->actingAs($this->userOf($org['worker']))->getJson('/api/people/change-requests')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->userOf($org['peer']))->getJson('/api/people/change-requests')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->login(UserRole::Viewer))->getJson('/api/people/change-requests')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->login(UserRole::Admin))->getJson('/api/people/change-requests?status=nope')->assertUnprocessable();
    }

    public function test_manager_approves_and_changes_apply_once(): void
    {
        $org = $this->org();
        $id = $this->actingAs($this->userOf($org['worker']))->postJson('/api/me/employee/change-requests', [
            'changes' => ['phone' => '+380509998877', 'emergency_contact' => 'Relative, +380500000000'],
        ])->json('data.id');

        // self, peer and an unrelated employee may not decide
        foreach ([$org['worker'], $org['peer'], $org['other']] as $who) {
            $this->actingAs($this->userOf($who))->postJson("/api/people/change-requests/$id/approve")->assertForbidden();
        }
        // the indirect manager may
        $this->actingAs($this->userOf($org['head']))->postJson("/api/people/change-requests/$id/approve", ['comment' => 'ok'])->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.decision_comment', 'ok');
        $worker = Employee::query()->findOrFail($org['worker']->id);
        $this->assertSame('+380509998877', $worker->phone);
        $this->assertSame('Relative, +380500000000', $worker->emergency_contact);

        $this->actingAs($this->userOf($org['lead']))->postJson("/api/people/change-requests/$id/reject")->assertStatus(409)
            ->assertJsonPath('code', 'already_decided');
    }

    public function test_reject_keeps_the_record_and_admin_can_decide(): void
    {
        $org = $this->org();
        $phone = $org['other']->phone;
        $id = $this->actingAs($this->userOf($org['other']))->postJson('/api/me/employee/change-requests', ['changes' => ['phone' => '000']])
            ->json('data.id');

        $this->actingAs($this->login(UserRole::Admin))->postJson("/api/people/change-requests/$id/reject")->assertOk()
            ->assertJsonPath('data.status', 'rejected');
        $this->assertSame($phone, Employee::query()->findOrFail($org['other']->id)->phone);
        $this->actingAs($this->login(UserRole::Admin))->postJson('/api/people/change-requests/99999/approve')->assertNotFound();
    }
}
