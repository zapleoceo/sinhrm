<?php

declare(strict_types=1);

namespace Tests\Feature\TimeOff;

use App\Modules\People\Contracts\EmployeeRepository;
use App\Modules\People\Models\Employee;
use App\Modules\TimeOff\Models\LeaveRequest;
use App\Modules\TimeOff\Models\LeaveType;
use App\Modules\TimeOff\Models\LedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\PeopleFixtures;
use Tests\Support\RecordingEmployeeRepository;
use Tests\TestCase;

/**
 * Overlap and balance checks are read-then-write: create/approve/cancel must hold the employee row lock
 * (SELECT … FOR UPDATE on Postgres) inside the transaction. SQLite has no FOR UPDATE, so the lock path is asserted
 * through the repository contract, and the checks themselves through sequential double requests.
 */
final class ConcurrencyGuardsTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    private RecordingEmployeeRepository $repo;

    private LeaveType $vacation;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 09:00:00');
        $this->repo = $this->app->make(RecordingEmployeeRepository::class);
        $this->app->instance(EmployeeRepository::class, $this->repo);
        $this->vacation = LeaveType::query()->where('code', 'vacation')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_create_approve_and_cancel_lock_the_employee_row_inside_the_transaction(): void
    {
        $org = $this->org();
        $this->grant($org['worker'], 10);
        $id = $this->actingAs($this->userOf($org['worker']))->postJson('/api/timeoff/requests', $this->payload('2026-10-12', '2026-10-13'))
            ->assertCreated()->json('data.id');
        $this->actingAs($this->userOf($org['lead']))->postJson("/api/timeoff/requests/$id/approve")->assertOk();
        $this->actingAs($this->userOf($org['lead']))->postJson("/api/timeoff/requests/$id/cancel")->assertOk();

        $this->assertCount(3, $this->repo->locks);
        foreach ($this->repo->locks as $lock) {
            $this->assertSame($org['worker']->id, $lock['id']);
            $this->assertTrue($lock['in_transaction']);
        }
    }

    public function test_the_same_request_twice_creates_one(): void
    {
        $org = $this->org();
        $this->grant($org['worker'], 10);
        $worker = $this->userOf($org['worker']);

        $this->actingAs($worker)->postJson('/api/timeoff/requests', $this->payload('2026-10-12', '2026-10-14'))->assertCreated();
        $this->actingAs($worker)->postJson('/api/timeoff/requests', $this->payload('2026-10-12', '2026-10-14'))
            ->assertUnprocessable()->assertJsonPath('code', 'overlap');
        $this->assertSame(1, LeaveRequest::query()->where('employee_id', $org['worker']->id)->count());
    }

    public function test_two_approvals_cannot_both_spend_the_same_balance(): void
    {
        $org = $this->org();
        $this->grant($org['worker'], 3);
        // The first was filed by an admin with override; the second must see the balance after the first approval.
        $first = $this->pending($org['worker'], '2026-10-12', '2026-10-13', 2);
        $first->update(['balance_override' => true]);
        $second = $this->pending($org['worker'], '2026-10-19', '2026-10-20', 2);

        $this->actingAs($this->userOf($org['lead']))->postJson("/api/timeoff/requests/{$first->id}/approve")->assertOk();
        $this->actingAs($this->userOf($org['head']))->postJson("/api/timeoff/requests/{$second->id}/approve")
            ->assertUnprocessable()->assertJsonPath('code', 'insufficient_balance');
        $this->assertSame(1.0, round((float) LedgerEntry::query()->where('employee_id', $org['worker']->id)->sum('delta'), 2));
    }

    /** @return array<string, mixed> */
    private function payload(string $from, string $to): array
    {
        return ['leave_type_id' => $this->vacation->id, 'starts_on' => $from, 'ends_on' => $to];
    }

    private function grant(Employee $employee, float $days): void
    {
        LedgerEntry::query()->create(['employee_id' => $employee->id, 'leave_type_id' => $this->vacation->id, 'delta' => $days, 'reason' => 'adjustment']);
    }

    private function pending(Employee $employee, string $from, string $to, float $days): LeaveRequest
    {
        return LeaveRequest::query()->create([
            'employee_id' => $employee->id, 'leave_type_id' => $this->vacation->id,
            'starts_on' => $from, 'ends_on' => $to, 'days' => $days, 'status' => 'pending',
        ]);
    }
}
