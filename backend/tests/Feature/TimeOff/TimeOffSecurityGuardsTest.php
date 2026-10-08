<?php

declare(strict_types=1);

namespace Tests\Feature\TimeOff;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\People\Models\Employee;
use App\Modules\TimeOff\Models\LeaveType;
use App\Modules\TimeOff\Models\LedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\NavBadgeAssertions;
use Tests\Support\PeopleFixtures;
use Tests\TestCase;

/**
 * Separation of duties in TimeOff: HR staff never decide or fund their own leave. Today is Monday 2026-10-05.
 * Synthetic data only.
 */
final class TimeOffSecurityGuardsTest extends TestCase
{
    use NavBadgeAssertions, PeopleFixtures, RefreshDatabase;

    private User $admin;

    private Employee $self;

    private LeaveType $vacation;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 09:00:00');
        $this->admin = $this->login(UserRole::Admin);
        $this->self = $this->employee(['full_name' => 'Admin Person'], $this->admin);
        $this->vacation = LeaveType::query()->where('code', 'vacation')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_hr_cannot_adjust_their_own_balance(): void
    {
        $other = $this->employee(['full_name' => 'Other Person']);
        $payload = fn (Employee $e): array => [
            'employee_id' => $e->id, 'leave_type_id' => $this->vacation->id, 'delta' => 10, 'comment' => 'bonus',
        ];

        $this->actingAs($this->admin)->postJson('/api/timeoff/balances/adjust', $payload($this->self))
            ->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->assertSame(0, LedgerEntry::query()->where('employee_id', $this->self->id)->count());

        $this->actingAs($this->admin)->postJson('/api/timeoff/balances/adjust', $payload($other))->assertCreated();
    }

    public function test_hr_cannot_decide_their_own_leave_request_and_it_stays_out_of_the_queue(): void
    {
        $other = $this->employee(['full_name' => 'Other Person'], $this->login());
        $this->grant($this->self);
        $this->grant($other);
        $mine = $this->request($this->admin);
        $theirs = $this->request($this->userOf($other));

        $this->actingAs($this->admin)->postJson('/api/timeoff/requests/'.$mine.'/approve')
            ->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->actingAs($this->admin)->postJson('/api/timeoff/requests/'.$mine.'/reject')
            ->assertForbidden()->assertJsonPath('code', 'forbidden');

        $this->actingAs($this->admin)->getJson('/api/timeoff/approvals')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $theirs);
        // The sidebar counter uses the same rule as the inbox.
        $this->assertBadgeMatchesList($this->admin, 'timeoff_approvals', '/api/timeoff/approvals', 1);

        $this->actingAs($this->admin)->getJson('/api/timeoff/requests/'.$mine)->assertOk()
            ->assertJsonPath('data.can_decide', false);

        // Another admin decides it; the author may still cancel their own pending request.
        $this->actingAs($this->login(UserRole::Admin))->postJson('/api/timeoff/requests/'.$mine.'/approve')->assertOk();
        $this->actingAs($this->admin)->postJson('/api/timeoff/requests/'.$mine.'/cancel')->assertOk();
    }

    private function grant(Employee $employee): void
    {
        LedgerEntry::query()->create([
            'employee_id' => $employee->id, 'leave_type_id' => $this->vacation->id, 'delta' => 30, 'reason' => 'adjustment',
        ]);
    }

    private function request(User $actor): int
    {
        return (int) $this->actingAs($actor)->postJson('/api/timeoff/requests', [
            'leave_type_id' => $this->vacation->id, 'starts_on' => '2026-10-12', 'ends_on' => '2026-10-16',
        ])->assertCreated()->json('data.id');
    }
}
