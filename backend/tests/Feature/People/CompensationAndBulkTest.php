<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\Audit\Models\AuditEntry;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Core\DTO\DataSubject;
use App\Modules\Core\Enums\DataSubjectType;
use App\Modules\Directory\Models\Department;
use App\Modules\People\Models\EmployeeCompensation;
use App\Modules\People\Privacy\CompensationPersonalData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\PeopleFixtures;
use Tests\TestCase;

final class CompensationAndBulkTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    public function test_hr_manages_compensation_employee_reads_own_others_forbidden(): void
    {
        Carbon::setTestNow('2026-10-05 10:00:00');
        $hr = $this->login(UserRole::HrManager);
        $org = $this->org();
        $worker = $org['worker'];
        $url = "/api/people/{$worker->id}/compensation";

        $this->actingAs($hr)->postJson($url, ['amount' => '30000', 'currency' => 'UAH', 'period' => 'month', 'effective_on' => '2026-01-01', 'reason' => 'hire'])
            ->assertCreated()->assertJsonPath('data.current.amount', '30000.00');
        $this->actingAs($hr)->postJson($url, ['amount' => 35000, 'currency' => 'UAH', 'period' => 'month', 'effective_on' => '2026-12-01'])->assertCreated()
            ->assertJsonPath('data.current.amount', '30000.00')->assertJsonCount(2, 'data.history');
        $this->actingAs($hr)->postJson($url, ['amount' => 1, 'currency' => 'GBP', 'period' => 'year', 'effective_on' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors(['currency', 'period', 'effective_on']);

        $this->actingAs($this->userOf($worker))->getJson('/api/me/employee/compensation')->assertOk()->assertJsonPath('data.current.currency', 'UAH');
        $this->actingAs($this->userOf($worker))->getJson($url)->assertForbidden();
        $this->actingAs($this->userOf($org['lead']))->getJson($url)->assertForbidden();
        $this->actingAs($this->login(UserRole::Recruiter))->postJson($url, ['amount' => 1, 'currency' => 'UAH', 'period' => 'hour', 'effective_on' => '2026-01-01'])->assertForbidden();

        // Audit keeps the fact of change but masks the amount.
        $entry = AuditEntry::query()->where('entity_type', 'employee_compensation')->firstOrFail();
        $this->assertStringNotContainsString('30000', (string) json_encode($entry->toArray()));

        // Privacy: exported, kept on erase (employment record).
        $provider = new CompensationPersonalData;
        $subject = new DataSubject(DataSubjectType::Employee, $worker->id);
        $this->assertCount(2, $provider->export($subject));
        $this->assertSame([], $provider->erase($subject));
        $this->assertSame(2, EmployeeCompensation::query()->where('employee_id', $worker->id)->count());
    }

    public function test_hr_reads_compensation_history_and_current(): void
    {
        Carbon::setTestNow('2026-10-05 10:00:00');
        $hr = $this->login(UserRole::HrManager);
        $worker = $this->org()['worker'];
        $url = "/api/people/{$worker->id}/compensation";

        $this->actingAs($hr)->getJson($url)->assertOk()->assertExactJson(['data' => ['current' => null, 'history' => []]]);

        $this->actingAs($hr)->postJson($url, ['amount' => 30000, 'currency' => 'UAH', 'period' => 'month', 'effective_on' => '2026-01-01', 'reason' => 'hire'])->assertCreated();
        $this->actingAs($hr)->postJson($url, ['amount' => 35000, 'currency' => 'UAH', 'period' => 'month', 'effective_on' => '2026-12-01'])->assertCreated();

        $this->actingAs($this->login(UserRole::Admin))->getJson($url)->assertOk()
            ->assertJsonPath('data.current.amount', '30000.00')
            ->assertJsonPath('data.current.effective_on', '2026-01-01')
            ->assertJsonPath('data.current.reason', 'hire')
            ->assertJsonPath('data.current.current', true)
            ->assertJsonCount(2, 'data.history')
            ->assertJsonPath('data.history.0.effective_on', '2026-12-01')
            ->assertJsonPath('data.history.0.amount', '35000.00')
            ->assertJsonPath('data.history.0.current', false)
            ->assertJsonPath('data.history.1.effective_on', '2026-01-01')
            ->assertJsonPath('data.history.1.current', true);

        $this->actingAs($hr)->getJson('/api/people/999999/compensation')->assertNotFound();
        Carbon::setTestNow();
    }

    /** A raise effective "today" is current from 00:00 Kyiv, not from 00:00 UTC (MySQL e2e, round 2). */
    public function test_compensation_effective_today_is_current_after_kyiv_midnight(): void
    {
        Carbon::setTestNow('2026-10-11 21:30:00'); // 2026-10-12 00:30 in Kyiv
        $hr = $this->login(UserRole::HrManager);
        $url = '/api/people/'.$this->org()['worker']->id.'/compensation';
        $this->actingAs($hr)->postJson($url, ['amount' => 30000, 'currency' => 'UAH', 'period' => 'month', 'effective_on' => '2026-01-01'])->assertCreated();
        $this->actingAs($hr)->postJson($url, ['amount' => 36000, 'currency' => 'UAH', 'period' => 'month', 'effective_on' => '2026-10-12'])->assertCreated();

        $this->actingAs($hr)->getJson($url)->assertOk()->assertJsonPath('data.current.effective_on', '2026-10-12');
        Carbon::setTestNow();
    }

    public function test_gender_is_hr_only(): void
    {
        $admin = $this->login(UserRole::Admin);
        $org = $this->org();
        $this->actingAs($admin)->patchJson("/api/people/{$org['peer']->id}", ['gender' => 'female'])->assertOk()->assertJsonPath('data.gender', 'female');
        $this->actingAs($admin)->patchJson("/api/people/{$org['peer']->id}", ['gender' => 'x'])->assertUnprocessable();
        $this->actingAs($this->userOf($org['lead']))->getJson("/api/people/{$org['peer']->id}")->assertOk()->assertJsonPath('data.gender', null);
    }

    public function test_bulk_update_goes_through_the_single_edit_rules(): void
    {
        $admin = $this->login(UserRole::Admin);
        $org = $this->org();
        $dept = Department::factory()->create();

        $this->actingAs($admin)->postJson('/api/people/bulk', ['action' => 'department', 'ids' => [$org['worker']->id, $org['peer']->id, 999999], 'department_id' => (string) $dept->id])
            ->assertOk()
            ->assertJsonPath('data.0.ok', true)->assertJsonPath('data.1.ok', true)
            ->assertJsonPath('data.2.error', 'not_found');
        $this->assertSame($dept->id, $org['worker']->fresh()?->department_id);

        // Making the head report to his own subordinate is a manager cycle — same rule as a single edit.
        $this->actingAs($admin)->postJson('/api/people/bulk', ['action' => 'manager', 'ids' => [$org['head']->id, $org['other']->id], 'manager_id' => $org['worker']->id])
            ->assertOk()->assertJsonPath('data.0.error', 'manager_cycle')->assertJsonPath('data.1.ok', true);

        $this->actingAs($admin)->postJson('/api/people/bulk', ['action' => 'department', 'ids' => range(1, 201), 'department_id' => $dept->id])->assertUnprocessable();
        $this->actingAs($this->userOf($org['lead']))->postJson('/api/people/bulk', ['action' => 'export', 'ids' => [$org['worker']->id]])->assertForbidden();

        $csv = $this->actingAs($admin)->post('/api/people/bulk', ['action' => 'export', 'ids' => [$org['worker']->id]])->assertOk()->streamedContent();
        $this->assertStringContainsString('Worker Person', $csv);
        $this->assertStringNotContainsString('worker.home@example.test', $csv);
    }
}
