<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Models\User;
use App\Modules\Audit\Models\AuditEntry;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\People\Models\Employee;
use App\Modules\TimeOff\Models\LeaveType;
use App\Modules\TimeOff\Models\LedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\PeopleFixtures;
use Tests\TestCase;

/**
 * Break-glass (PeopleContext::canDecideOrBreakGlass, default decision, the owner may change it): a sole superadmin
 * decides their own change request, leave, salary and leave balance, and each such action is audited as
 * self_decision. With a second active superadmin/admin it is 403 as before; an hr_manager never decides their own.
 * Today is Monday 2026-10-05. Synthetic data only.
 */
final class BreakGlassSelfDecisionTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    private LeaveType $vacation;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 09:00:00');
        $this->vacation = LeaveType::query()->where('code', 'vacation')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_sole_superadmin_decides_their_own_and_every_decision_is_audited(): void
    {
        $owner = $this->login(UserRole::Superadmin);
        $self = $this->employee(['full_name' => 'Owner Person'], $owner);
        // A plain employee and an hr_manager do not count as "another administrator".
        $this->login(UserRole::HrManager);
        $this->login(UserRole::Employee);

        $change = $this->actingAs($owner)->postJson('/api/me/employee/change-requests', ['changes' => ['address' => 'New street 2']])
            ->assertCreated()->assertJsonPath('data.can_decide', true)->json('data.id');
        $this->actingAs($owner)->postJson('/api/people/change-requests/'.$change.'/approve')->assertOk();

        $this->actingAs($owner)->postJson('/api/timeoff/balances/adjust', [
            'employee_id' => $self->id, 'leave_type_id' => $this->vacation->id, 'delta' => 10, 'comment' => 'start balance',
        ])->assertCreated();

        $leave = $this->leave($owner);
        $this->actingAs($owner)->getJson('/api/timeoff/approvals')->assertOk()->assertJsonPath('data.0.id', $leave);
        $this->actingAs($owner)->postJson('/api/timeoff/requests/'.$leave.'/approve')->assertOk();

        $this->actingAs($owner)->postJson('/api/people/'.$self->id.'/compensation', $this->salary())->assertCreated();

        $this->actingAs($owner)->getJson('/api/people/'.$self->id)->assertOk()->assertJsonPath('data.access.decide', true);

        $entries = AuditEntry::query()->where('entity_type', 'employee')->where('entity_id', $self->id)->get()
            ->filter(static fn (AuditEntry $e): bool => ($e->meta['self_decision'] ?? false) === true);
        $this->assertEqualsCanonicalizing(
            ['people.change_approved', 'timeoff.balance_adjusted', 'timeoff.request_approved', 'people.compensation_added'],
            $entries->map(static fn (AuditEntry $e): string => (string) $e->meta['operation'])->values()->all(),
        );
        $this->assertTrue($entries->every(static fn (AuditEntry $e): bool => $e->user_id === $owner->id));
    }

    public function test_a_superadmin_with_an_active_admin_peer_gets_403_on_their_own(): void
    {
        $this->assertPeerBlocksBreakGlass(UserRole::Admin);
    }

    public function test_a_superadmin_with_an_active_superadmin_peer_gets_403_on_their_own(): void
    {
        $this->assertPeerBlocksBreakGlass(UserRole::Superadmin);
    }

    public function test_an_hr_manager_never_decides_their_own_even_alone(): void
    {
        $hr = $this->login(UserRole::HrManager);
        $self = $this->employee(['full_name' => 'Hr Person'], $hr);

        $this->assertForbiddenOnOwn($hr, $self);
    }

    public function test_a_sole_admin_without_superadmin_role_is_not_break_glass(): void
    {
        $admin = $this->login(UserRole::Admin);
        $self = $this->employee(['full_name' => 'Admin Person'], $admin);

        $this->assertForbiddenOnOwn($admin, $self);
    }

    private function assertPeerBlocksBreakGlass(UserRole $peerRole): void
    {
        $owner = $this->login(UserRole::Superadmin);
        $self = $this->employee(['full_name' => 'Owner Person'], $owner);
        $this->login($peerRole);

        $this->assertForbiddenOnOwn($owner, $self);
        $this->assertSame(0, AuditEntry::query()->get()
            ->filter(static fn (AuditEntry $e): bool => isset($e->meta['self_decision']))->count());
    }

    private function assertForbiddenOnOwn(User $actor, Employee $self): void
    {
        $change = $this->actingAs($actor)->postJson('/api/me/employee/change-requests', ['changes' => ['address' => 'New street 2']])
            ->assertCreated()->assertJsonPath('data.can_decide', false)->json('data.id');
        $this->actingAs($actor)->postJson('/api/people/change-requests/'.$change.'/approve')
            ->assertForbidden()->assertJsonPath('code', 'forbidden');

        $this->actingAs($actor)->postJson('/api/timeoff/balances/adjust', [
            'employee_id' => $self->id, 'leave_type_id' => $this->vacation->id, 'delta' => 10, 'comment' => 'bonus',
        ])->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->assertSame(0, LedgerEntry::query()->where('employee_id', $self->id)->count());

        LedgerEntry::query()->create([
            'employee_id' => $self->id, 'leave_type_id' => $this->vacation->id, 'delta' => 30, 'reason' => 'adjustment',
        ]);
        $leave = $this->leave($actor);
        $this->actingAs($actor)->postJson('/api/timeoff/requests/'.$leave.'/approve')
            ->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->actingAs($actor)->getJson('/api/timeoff/approvals')->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($actor)->postJson('/api/people/'.$self->id.'/compensation', $this->salary())
            ->assertForbidden()->assertJsonPath('code', 'forbidden');
    }

    private function leave(User $actor): int
    {
        return (int) $this->actingAs($actor)->postJson('/api/timeoff/requests', [
            'leave_type_id' => $this->vacation->id, 'starts_on' => '2026-10-12', 'ends_on' => '2026-10-14',
        ])->assertCreated()->json('data.id');
    }

    /** @return array<string, mixed> */
    private function salary(): array
    {
        return ['amount' => 1000, 'currency' => 'UAH', 'period' => 'month', 'effective_on' => '2026-01-01'];
    }
}
