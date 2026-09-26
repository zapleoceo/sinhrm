<?php

declare(strict_types=1);

namespace Tests\Feature\TimeOff;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\TimeOff\Models\LeaveType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PeopleFixtures;
use Tests\TestCase;

/** Leave types, policies, holidays, balance adjustments. Synthetic data only. */
final class SettingsApiTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    public function test_defaults_are_seeded_and_readable_by_everyone(): void
    {
        $this->getJson('/api/timeoff/types')->assertUnauthorized();
        $viewer = $this->login(UserRole::Viewer);

        $this->actingAs($viewer)->getJson('/api/timeoff/types')->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.code', 'vacation')
            ->assertJsonPath('data.0.tracks_balance', true)
            ->assertJsonPath('data.1.code', 'sick')
            ->assertJsonPath('data.1.tracks_balance', false)
            ->assertJsonPath('data.2.code', 'day_off')
            ->assertJsonPath('data.2.paid', false);
        $this->actingAs($this->login(UserRole::Admin))->getJson('/api/timeoff/policies')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.leave_type.name', 'Vacation')
            ->assertJsonPath('data.0.annual_days', 24)
            ->assertJsonPath('data.0.accrual_mode', 'yearly_upfront')
            ->assertJsonPath('data.0.branch_id', null);
        $this->actingAs($viewer)->getJson('/api/timeoff/policies')->assertForbidden();
    }

    public function test_admin_manages_types(): void
    {
        $admin = $this->login(UserRole::Admin);

        $id = $this->actingAs($admin)->postJson('/api/timeoff/types', [
            'name' => 'Remote work', 'code' => 'remote', 'unit' => 'hours', 'color' => '#12ab34', 'tracks_balance' => false,
        ])->assertCreated()->assertJsonPath('data.unit', 'hours')->assertJsonPath('data.requires_approval', true)->json('data.id');
        $this->actingAs($admin)->postJson('/api/timeoff/types', ['name' => 'Dup', 'code' => 'remote'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->actingAs($admin)->postJson('/api/timeoff/types', ['name' => 'Bad', 'code' => 'Bad Code', 'color' => 'red'])
            ->assertUnprocessable()->assertJsonValidationErrors(['code', 'color']);
        $this->actingAs($admin)->patchJson("/api/timeoff/types/$id", ['active' => false])->assertOk()->assertJsonPath('data.active', false);

        $this->actingAs($admin)->getJson('/api/timeoff/types')->assertOk()->assertJsonCount(3, 'data');
        $this->actingAs($admin)->getJson('/api/timeoff/types?all=1')->assertOk()->assertJsonCount(4, 'data');
        $recruiter = $this->login();
        $this->actingAs($recruiter)->getJson('/api/timeoff/types?all=1')->assertOk()->assertJsonCount(3, 'data');
        $this->actingAs($recruiter)->postJson('/api/timeoff/types', ['name' => 'X', 'code' => 'x'])->assertForbidden();
        $this->actingAs($recruiter)->patchJson("/api/timeoff/types/$id", ['active' => true])->assertForbidden();
    }

    public function test_branch_policy_overrides_the_default(): void
    {
        $admin = $this->login(UserRole::Admin);
        $branch = Branch::factory()->create();
        $vacation = LeaveType::query()->where('code', 'vacation')->firstOrFail();
        $employee = $this->employee(['branch_id' => $branch->id], $this->login());
        $elsewhere = $this->employee([], $this->login());

        $id = $this->actingAs($admin)->postJson('/api/timeoff/policies', [
            'leave_type_id' => $vacation->id, 'branch_id' => (string) $branch->id, 'annual_days' => '28', 'accrual_mode' => 'monthly', 'carry_over_max' => 5,
        ])->assertCreated()->assertJsonPath('data.branch.id', $branch->id)->assertJsonPath('data.annual_days', 28)->json('data.id');
        $this->actingAs($admin)->postJson('/api/timeoff/policies', ['leave_type_id' => $vacation->id, 'annual_days' => 400])->assertUnprocessable();
        $this->actingAs($admin)->postJson('/api/timeoff/policies', ['leave_type_id' => $vacation->id, 'annual_days' => 10, 'accrual_mode' => 'weekly'])
            ->assertUnprocessable();

        $this->actingAs($this->userOf($employee))->getJson('/api/timeoff/balances')->assertOk()
            ->assertJsonPath('data.0.policy.id', $id)->assertJsonPath('data.0.policy.carry_over_max', 5);
        $this->actingAs($this->userOf($elsewhere))->getJson('/api/timeoff/balances')->assertOk()
            ->assertJsonPath('data.0.policy.annual_days', 24);
        $this->actingAs($admin)->patchJson("/api/timeoff/policies/$id", ['active' => false])->assertOk();
        $this->actingAs($this->userOf($employee))->getJson('/api/timeoff/balances')->assertJsonPath('data.0.policy.annual_days', 24);
    }

    public function test_holidays_crud(): void
    {
        $admin = $this->login(UserRole::Admin);
        $branch = Branch::factory()->create();

        $id = $this->actingAs($admin)->postJson('/api/timeoff/holidays', ['date' => '2026-12-25', 'name' => 'Test Day'])->assertCreated()
            ->assertJsonPath('data.date', '2026-12-25')->assertJsonPath('data.branch', null)->json('data.id');
        $this->actingAs($admin)->postJson('/api/timeoff/holidays', ['date' => '2026-12-31', 'name' => 'Branch Day', 'branch_id' => $branch->id])->assertCreated();
        $this->actingAs($admin)->postJson('/api/timeoff/holidays', ['date' => '25.12.2026', 'name' => 'Bad'])->assertUnprocessable();
        $this->actingAs($admin)->patchJson("/api/timeoff/holidays/$id", ['name' => 'Renamed'])->assertOk()->assertJsonPath('data.name', 'Renamed');

        $viewer = $this->login(UserRole::Viewer);
        $this->actingAs($viewer)->getJson('/api/timeoff/holidays?year=2026')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.date', '2026-12-31');
        $this->actingAs($viewer)->getJson('/api/timeoff/holidays?year=2027')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($viewer)->getJson('/api/timeoff/holidays?branch_id='.Branch::factory()->create()->id)->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($viewer)->deleteJson("/api/timeoff/holidays/$id")->assertForbidden();
        $this->actingAs($admin)->deleteJson("/api/timeoff/holidays/$id")->assertNoContent();
        $this->actingAs($admin)->deleteJson("/api/timeoff/holidays/$id")->assertNotFound();
    }

    public function test_admin_adjusts_a_balance(): void
    {
        $admin = $this->login(UserRole::Admin);
        $employee = $this->employee([], $this->login());
        $vacation = LeaveType::query()->where('code', 'vacation')->firstOrFail();
        $sick = LeaveType::query()->where('code', 'sick')->firstOrFail();

        $this->actingAs($admin)->postJson('/api/timeoff/balances/adjust', [
            'employee_id' => $employee->id, 'leave_type_id' => $vacation->id, 'delta' => '2.5', 'comment' => 'Transfer from old system',
        ])->assertCreated()->assertJsonPath('data.0.balance', 2.5);
        $this->actingAs($admin)->postJson('/api/timeoff/balances/adjust', ['employee_id' => $employee->id, 'leave_type_id' => $vacation->id, 'delta' => 0])
            ->assertUnprocessable();
        $this->actingAs($admin)->postJson('/api/timeoff/balances/adjust', ['employee_id' => $employee->id, 'leave_type_id' => $sick->id, 'delta' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('leave_type_id');
        $this->actingAs($this->userOf($employee))->postJson('/api/timeoff/balances/adjust', [
            'employee_id' => $employee->id, 'leave_type_id' => $vacation->id, 'delta' => 10,
        ])->assertForbidden();
        $this->actingAs($this->userOf($employee))->getJson('/api/timeoff/balances/history')->assertOk()
            ->assertJsonPath('data.0.delta', 2.5)->assertJsonPath('data.0.comment', 'Transfer from old system');
    }
}
