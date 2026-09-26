<?php

declare(strict_types=1);

namespace Tests\Feature\TimeOff;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\People\Models\Employee;
use App\Modules\TimeOff\Models\Holiday;
use App\Modules\TimeOff\Models\LeaveRequest;
use App\Modules\TimeOff\Models\LeaveType;
use App\Modules\TimeOff\Models\LedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\NavBadgeAssertions;
use Tests\Support\PeopleFixtures;
use Tests\TestCase;

/**
 * Today is Monday 2026-10-05. Week of 2026-10-12 (Mon) … 2026-10-18 (Sun) = 5 working days.
 * Synthetic data only.
 */
final class LeaveRequestApiTest extends TestCase
{
    use NavBadgeAssertions, PeopleFixtures, RefreshDatabase;

    /** @var array{head: Employee, lead: Employee, worker: Employee, peer: Employee, other: Employee} */
    private array $org;

    private LeaveType $vacation;

    private LeaveType $sick;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 09:00:00');
        $this->org = $this->org();
        $this->vacation = LeaveType::query()->where('code', 'vacation')->firstOrFail();
        $this->sick = LeaveType::query()->where('code', 'sick')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/timeoff/requests')->assertUnauthorized();
        $this->postJson('/api/timeoff/requests', [])->assertUnauthorized();
        $this->getJson('/api/timeoff/balances')->assertUnauthorized();
        $this->getJson('/api/timeoff/calendar')->assertUnauthorized();
        $this->getJson('/api/timeoff/approvals')->assertUnauthorized();
    }

    public function test_day_count_excludes_weekends_and_holidays_with_half_days(): void
    {
        $this->grant($this->org['worker'], 30);
        Holiday::query()->create(['date' => '2026-10-14', 'name' => 'Test holiday']);
        Holiday::query()->create(['date' => '2026-10-15', 'name' => 'Other branch holiday', 'branch_id' => $this->branchId()]);
        $worker = $this->userOf($this->org['worker']);
        $preview = fn (string $query) => $this->actingAs($worker)->getJson('/api/timeoff/requests/preview?leave_type_id='.$this->vacation->id.'&'.$query);

        $preview('starts_on=2026-10-12&ends_on=2026-10-18')->assertOk()
            ->assertJsonPath('data.days', 4)
            ->assertJsonPath('data.holidays.0.name', 'Test holiday')
            ->assertJsonPath('data.available', 30)
            ->assertJsonPath('data.sufficient', true)
            ->assertJsonPath('data.overlap', false);
        $preview('starts_on=2026-10-12&ends_on=2026-10-16&half_day=start')->assertOk()->assertJsonPath('data.days', 3.5);
        $preview('starts_on=2026-10-12&ends_on=2026-10-16&half_day=end')->assertOk()->assertJsonPath('data.days', 3.5);
        $preview('starts_on=2026-10-12&ends_on=2026-10-12&half_day=start')->assertOk()->assertJsonPath('data.days', 0.5);
        // half day on a weekend edge costs nothing extra
        $preview('starts_on=2026-10-12&ends_on=2026-10-18&half_day=end')->assertOk()->assertJsonPath('data.days', 4);
        $preview('starts_on=2026-10-17&ends_on=2026-10-18')->assertOk()->assertJsonPath('data.days', 0);
        $preview('starts_on=2026-10-18&ends_on=2026-10-12')->assertUnprocessable();
        $preview('starts_on=2026-10-12&ends_on=2026-10-13&half_day=middle')->assertUnprocessable();

        $this->actingAs($worker)->postJson('/api/timeoff/requests', $this->payload('2026-10-17', '2026-10-18'))
            ->assertUnprocessable()->assertJsonPath('code', 'no_working_days');
        $this->actingAs($worker)->postJson('/api/timeoff/requests', $this->payload('2026-10-12', '2026-10-18') + ['half_day' => 'start'])
            ->assertCreated()
            ->assertJsonPath('data.days', 3.5)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.can_decide', false)
            ->assertJsonPath('data.can_cancel', true);
    }

    public function test_balance_check_and_admin_override(): void
    {
        $this->grant($this->org['worker'], 3);
        $worker = $this->userOf($this->org['worker']);

        $this->actingAs($worker)->postJson('/api/timeoff/requests', $this->payload('2026-10-12', '2026-10-16'))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'insufficient_balance')
            ->assertJsonPath('available', 3)
            ->assertJsonPath('requested', 5);
        // the override flag is ignored for non-admins
        $this->actingAs($worker)->postJson('/api/timeoff/requests', $this->payload('2026-10-12', '2026-10-16') + ['override_balance' => true])
            ->assertUnprocessable()->assertJsonPath('code', 'insufficient_balance');
        // pending requests reserve days
        $this->actingAs($worker)->postJson('/api/timeoff/requests', $this->payload('2026-10-12', '2026-10-13'))->assertCreated();
        $this->actingAs($worker)->postJson('/api/timeoff/requests', $this->payload('2026-10-19', '2026-10-20'))
            ->assertUnprocessable()->assertJsonPath('available', 1);

        $admin = $this->login(UserRole::Admin);
        $this->actingAs($admin)->postJson('/api/timeoff/requests', $this->payload('2026-10-19', '2026-10-23') + [
            'employee_id' => $this->org['worker']->id, 'override_balance' => true,
        ])->assertCreated()->assertJsonPath('data.balance_override', true);

        // untracked types (sick) never check a balance and never touch the ledger
        $id = $this->actingAs($worker)->postJson('/api/timeoff/requests', [
            'leave_type_id' => $this->sick->id, 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-06',
        ])->assertCreated()->json('data.id');
        $this->actingAs($this->userOf($this->org['lead']))->postJson("/api/timeoff/requests/$id/approve")->assertOk();
        $this->assertSame(0, LedgerEntry::query()->where('leave_type_id', $this->sick->id)->count());
    }

    public function test_overlap_with_pending_or_approved_is_rejected(): void
    {
        $this->grant($this->org['worker'], 30);
        $worker = $this->userOf($this->org['worker']);
        $id = $this->actingAs($worker)->postJson('/api/timeoff/requests', $this->payload('2026-10-12', '2026-10-14'))->json('data.id');

        $this->actingAs($worker)->postJson('/api/timeoff/requests', $this->payload('2026-10-14', '2026-10-16'))
            ->assertUnprocessable()->assertJsonPath('code', 'overlap');
        $this->actingAs($worker)->getJson('/api/timeoff/requests/preview?leave_type_id='.$this->vacation->id.'&starts_on=2026-10-13&ends_on=2026-10-13')
            ->assertOk()->assertJsonPath('data.overlap', true);
        // another employee may take the same days
        $this->grant($this->org['peer'], 5);
        $this->actingAs($this->userOf($this->org['peer']))->postJson('/api/timeoff/requests', $this->payload('2026-10-12', '2026-10-14'))->assertCreated();
        // a rejected request frees the dates
        $this->actingAs($this->userOf($this->org['lead']))->postJson("/api/timeoff/requests/$id/reject", ['comment' => 'busy week'])->assertOk()
            ->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.decision_comment', 'busy week');
        $this->actingAs($worker)->postJson('/api/timeoff/requests', $this->payload('2026-10-14', '2026-10-16'))->assertCreated();
    }

    public function test_approval_writes_the_ledger_and_cancel_reverts_it(): void
    {
        $this->grant($this->org['worker'], 10);
        $worker = $this->userOf($this->org['worker']);
        $id = $this->actingAs($worker)->postJson('/api/timeoff/requests', $this->payload('2026-10-12', '2026-10-16'))->json('data.id');

        // self, peer, unrelated and a viewer may not approve
        foreach ([$worker, $this->userOf($this->org['peer']), $this->userOf($this->org['other']), $this->login(UserRole::Viewer)] as $user) {
            $this->actingAs($user)->postJson("/api/timeoff/requests/$id/approve")->assertForbidden()->assertJsonPath('code', 'forbidden');
        }
        $lead = $this->userOf($this->org['lead']);
        $this->actingAs($lead)->postJson("/api/timeoff/requests/$id/approve")->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.approver.id', $lead->id);
        $this->actingAs($lead)->postJson("/api/timeoff/requests/$id/approve")->assertStatus(409)->assertJsonPath('code', 'invalid_status');
        $this->assertSame(-5.0, (float) LedgerEntry::query()->where('reason', 'request')->where('reference_id', $id)->sum('delta'));
        $this->actingAs($worker)->getJson('/api/timeoff/balances')->assertOk()
            ->assertJsonPath('data.0.leave_type.code', 'vacation')
            ->assertJsonPath('data.0.balance', 5)
            ->assertJsonPath('data.0.used_this_year', 5);

        // unrelated employee cannot cancel; the worker can (the leave has not started)
        $this->actingAs($this->userOf($this->org['other']))->postJson("/api/timeoff/requests/$id/cancel")->assertForbidden();
        $this->actingAs($worker)->postJson("/api/timeoff/requests/$id/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(0.0, (float) LedgerEntry::query()->where('reason', 'request')->where('reference_id', $id)->sum('delta'));
        $this->actingAs($worker)->postJson("/api/timeoff/requests/$id/cancel")->assertStatus(409);
        $this->actingAs($worker)->getJson('/api/timeoff/balances')->assertJsonPath('data.0.balance', 10);
    }

    public function test_started_leave_is_cancelled_only_by_a_decider(): void
    {
        $this->grant($this->org['worker'], 10);
        $request = LeaveRequest::query()->create([
            'employee_id' => $this->org['worker']->id, 'leave_type_id' => $this->vacation->id,
            'starts_on' => '2026-10-05', 'ends_on' => '2026-10-06', 'days' => 2, 'status' => 'approved',
        ]);

        $this->actingAs($this->userOf($this->org['worker']))->postJson("/api/timeoff/requests/{$request->id}/cancel")->assertForbidden();
        $this->actingAs($this->login(UserRole::Admin))->postJson("/api/timeoff/requests/{$request->id}/cancel")->assertOk();
        $this->assertSame(2.0, (float) LedgerEntry::query()->where('reference_id', $request->id)->sum('delta'));
    }

    public function test_auto_approved_type_and_filing_for_others(): void
    {
        $type = LeaveType::query()->create(['name' => 'Remote day', 'code' => 'remote', 'requires_approval' => false, 'tracks_balance' => false]);
        $payload = ['leave_type_id' => $type->id, 'starts_on' => '2026-10-07', 'ends_on' => '2026-10-07', 'employee_id' => $this->org['worker']->id];

        // a manager above files for a report; an unrelated colleague may not
        $this->actingAs($this->userOf($this->org['other']))->postJson('/api/timeoff/requests', $payload)->assertForbidden();
        $this->actingAs($this->userOf($this->org['peer']))->postJson('/api/timeoff/requests', $payload)->assertForbidden();
        $this->actingAs($this->userOf($this->org['head']))->postJson('/api/timeoff/requests', $payload)->assertCreated()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.employee.id', $this->org['worker']->id);
        $this->actingAs($this->login(UserRole::Viewer))->postJson('/api/timeoff/requests', $this->payload('2026-10-12', '2026-10-12'))
            ->assertNotFound()->assertJsonPath('code', 'no_employee');
        $type->update(['active' => false]);
        $this->actingAs($this->userOf($this->org['worker']))->postJson('/api/timeoff/requests', ['leave_type_id' => $type->id, 'starts_on' => '2026-10-08', 'ends_on' => '2026-10-08'])
            ->assertUnprocessable()->assertJsonPath('code', 'inactive_type');
    }

    public function test_list_balances_and_approvals_are_scoped(): void
    {
        foreach (['worker', 'peer', 'other', 'lead'] as $who) {
            $this->grant($this->org[$who], 10);
            $this->actingAs($this->userOf($this->org[$who]))->postJson('/api/timeoff/requests', $this->payload('2026-10-12', '2026-10-12'))->assertCreated();
        }

        $this->actingAs($this->userOf($this->org['worker']))->getJson('/api/timeoff/requests')->assertOk()->assertJsonPath('meta.total', 1);
        $this->actingAs($this->userOf($this->org['lead']))->getJson('/api/timeoff/requests?perPage=20')->assertOk()->assertJsonPath('meta.total', 3);
        $this->actingAs($this->userOf($this->org['head']))->getJson('/api/timeoff/requests?status=pending')->assertOk()->assertJsonPath('meta.total', 3);
        $this->actingAs($this->login(UserRole::Admin))->getJson('/api/timeoff/requests')->assertOk()->assertJsonPath('meta.total', 4);
        $this->actingAs($this->login(UserRole::Viewer))->getJson('/api/timeoff/requests')->assertOk()->assertJsonPath('meta.total', 0);
        $this->actingAs($this->login(UserRole::Admin))->getJson('/api/timeoff/requests?perPage=abc')->assertUnprocessable();

        // approvals inbox: the lead sees the two reports, not their own request; the head sees three
        $this->actingAs($this->userOf($this->org['lead']))->getJson('/api/timeoff/approvals')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($this->userOf($this->org['head']))->getJson('/api/timeoff/approvals')->assertOk()->assertJsonCount(3, 'data');
        $this->actingAs($this->userOf($this->org['worker']))->getJson('/api/timeoff/approvals')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->login(UserRole::Admin))->getJson('/api/timeoff/approvals')->assertOk()->assertJsonCount(4, 'data');
        // sidebar badge = the same approvals list
        $this->assertBadgeMatchesList($this->userOf($this->org['lead']), 'timeoff_approvals', '/api/timeoff/approvals', 2);
        $this->assertBadgeMatchesList($this->userOf($this->org['head']), 'timeoff_approvals', '/api/timeoff/approvals', 3);
        $this->assertBadgeMatchesList($this->userOf($this->org['worker']), 'timeoff_approvals', '/api/timeoff/approvals', 0);
        $this->assertBadgeMatchesList($this->login(UserRole::Admin), 'timeoff_approvals', '/api/timeoff/approvals', 4);

        // balances of someone else: managers above and admins only
        $worker = $this->org['worker']->id;
        $this->actingAs($this->userOf($this->org['head']))->getJson("/api/timeoff/balances?employee_id=$worker")->assertOk()
            ->assertJsonPath('meta.employee.id', $worker)->assertJsonPath('data.0.pending', 1);
        $this->actingAs($this->userOf($this->org['peer']))->getJson("/api/timeoff/balances?employee_id=$worker")->assertForbidden();
        $this->actingAs($this->login(UserRole::Viewer))->getJson('/api/timeoff/balances')->assertNotFound();
        $this->actingAs($this->userOf($this->org['head']))->getJson("/api/timeoff/balances/history?employee_id=$worker")->assertOk()
            ->assertJsonPath('data.0.reason', 'adjustment');
        $other = $this->org['other']->id;
        $this->actingAs($this->userOf($this->org['head']))->getJson("/api/timeoff/requests/{$this->requestOf($other)}")->assertForbidden();
        $this->actingAs($this->userOf($this->org['other']))->getJson("/api/timeoff/requests/{$this->requestOf($other)}")->assertOk();
    }

    public function test_calendar_scope(): void
    {
        $this->grant($this->org['worker'], 10);
        $this->grant($this->org['other'], 10);
        $this->actingAs($this->userOf($this->org['worker']))->postJson('/api/timeoff/requests', $this->payload('2026-10-12', '2026-10-13'))->assertCreated();
        $this->actingAs($this->userOf($this->org['other']))->postJson('/api/timeoff/requests', $this->payload('2026-10-12', '2026-10-13'))->assertCreated();
        Holiday::query()->create(['date' => '2026-10-14', 'name' => 'Test holiday']);

        $range = '/api/timeoff/calendar?from=2026-10-01&to=2026-10-31';
        // the peer sees the worker (same manager), not the unrelated employee
        $this->actingAs($this->userOf($this->org['peer']))->getJson($range)->assertOk()
            ->assertJsonCount(1, 'data.absences')
            ->assertJsonPath('data.absences.0.employee.id', $this->org['worker']->id)
            ->assertJsonPath('data.holidays.0.date', '2026-10-14');
        $this->actingAs($this->userOf($this->org['head']))->getJson($range)->assertOk()->assertJsonCount(1, 'data.absences');
        $this->actingAs($this->login(UserRole::Admin))->getJson($range)->assertOk()->assertJsonCount(2, 'data.absences');
        $this->actingAs($this->login(UserRole::Viewer))->getJson($range)->assertOk()->assertJsonCount(0, 'data.absences');
        $this->actingAs($this->login(UserRole::Admin))->getJson('/api/timeoff/calendar')->assertOk()
            ->assertJsonPath('meta.from', '2026-10-01')->assertJsonPath('meta.to', '2026-10-31');
        $this->actingAs($this->login(UserRole::Admin))->getJson('/api/timeoff/calendar?from=2026-01-01&to=2026-12-31')
            ->assertUnprocessable()->assertJsonPath('code', 'range_too_long');
        $absence = $this->actingAs($this->userOf($this->org['peer']))->getJson($range)->json('data.absences.0');
        $this->assertIsArray($absence);
        $this->assertArrayNotHasKey('comment', $absence);
    }

    public function test_dashboard_shows_who_is_out_and_my_approvals(): void
    {
        $this->grant($this->org['worker'], 10);
        LeaveRequest::query()->create([
            'employee_id' => $this->org['worker']->id, 'leave_type_id' => $this->vacation->id,
            'starts_on' => '2026-10-05', 'ends_on' => '2026-10-07', 'days' => 3, 'status' => 'approved',
        ]);
        $this->actingAs($this->userOf($this->org['peer']))->postJson('/api/timeoff/requests', [
            'leave_type_id' => $this->sick->id, 'starts_on' => '2026-10-19', 'ends_on' => '2026-10-19',
        ])->assertCreated();

        $this->actingAs($this->userOf($this->org['lead']))->getJson('/api/dashboard')->assertOk()
            ->assertJsonCount(1, 'data.timeoff.out_today')
            ->assertJsonPath('data.timeoff.out_today.0.employee.id', $this->org['worker']->id)
            ->assertJsonPath('data.timeoff.my_approvals.count', 1)
            ->assertJsonPath('data.timeoff.my_approvals.items.0.employee.id', $this->org['peer']->id);
        $this->actingAs($this->userOf($this->org['other']))->getJson('/api/dashboard')->assertOk()
            ->assertJsonCount(0, 'data.timeoff.out_today')
            ->assertJsonPath('data.timeoff.my_approvals.count', 0);
    }

    /** @return array<string, mixed> */
    private function payload(string $from, string $to): array
    {
        return ['leave_type_id' => $this->vacation->id, 'starts_on' => $from, 'ends_on' => $to];
    }

    private function grant(Employee $employee, float $days): void
    {
        LedgerEntry::query()->create([
            'employee_id' => $employee->id, 'leave_type_id' => $this->vacation->id, 'delta' => $days, 'reason' => 'adjustment',
        ]);
    }

    private function branchId(): int
    {
        return Branch::factory()->create()->id;
    }

    private function requestOf(int $employeeId): int
    {
        return LeaveRequest::query()->where('employee_id', $employeeId)->firstOrFail()->id;
    }
}
