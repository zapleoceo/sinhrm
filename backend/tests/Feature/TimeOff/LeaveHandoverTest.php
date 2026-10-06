<?php

declare(strict_types=1);

namespace Tests\Feature\TimeOff;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\People\Models\Employee;
use App\Modules\Scripts\Models\Task;
use App\Modules\TimeOff\Models\LeaveRequest;
use App\Modules\TimeOff\Models\LeaveType;
use App\Modules\TimeOff\Models\LedgerEntry;
use App\Modules\TimeOff\Services\LeaveHandoverTasks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\PeopleFixtures;
use Tests\TestCase;

/**
 * PROD-13: optional "who takes over the work" on a leave / sick-leave request and the handover task.
 * Today is Monday 2026-10-05; the request is 2026-10-12 … 2026-10-16. Synthetic data only.
 */
final class LeaveHandoverTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    /** @var array{head: Employee, lead: Employee, worker: Employee, peer: Employee, other: Employee} */
    private array $org;

    private LeaveType $vacation;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 09:00:00');
        $this->org = $this->org();
        $this->vacation = LeaveType::query()->where('code', 'vacation')->firstOrFail();
        LedgerEntry::query()->create([
            'employee_id' => $this->org['worker']->id, 'leave_type_id' => $this->vacation->id, 'delta' => 30, 'reason' => 'adjustment',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_request_without_handover_is_unchanged(): void
    {
        $this->file([])->assertCreated()->assertJsonPath('data.handover_to', null);
        $this->assertNull(LeaveRequest::query()->firstOrFail()->handover_to_employee_id);
    }

    public function test_handover_is_saved_and_returned_as_id_and_name_in_list_detail_and_calendar(): void
    {
        $peer = $this->org['peer'];
        // String parameter, as a query/form would send it.
        $id = $this->file(['handover_to_employee_id' => (string) $peer->id])->assertCreated()
            ->assertJsonPath('data.handover_to', ['id' => $peer->id, 'full_name' => 'Peer Person'])
            ->json('data.id');
        $worker = $this->userOf($this->org['worker']);
        $lead = $this->userOf($this->org['lead']);

        $this->actingAs($worker)->getJson("/api/timeoff/requests/$id")->assertOk()->assertJsonPath('data.handover_to.full_name', 'Peer Person');
        $this->actingAs($lead)->getJson('/api/timeoff/requests')->assertOk()->assertJsonPath('data.0.handover_to.id', $peer->id);
        $this->actingAs($lead)->getJson('/api/timeoff/approvals')->assertOk()->assertJsonPath('data.0.handover_to.id', $peer->id);
        $this->actingAs($lead)->getJson('/api/timeoff/calendar?from=2026-10-01&to=2026-10-31')->assertOk()
            ->assertJsonPath('data.absences.0.handover_to', ['id' => $peer->id, 'full_name' => 'Peer Person']);
        // Seeing the request still follows the request's own scope: an unrelated colleague gets 403.
        $this->actingAs($this->userOf($this->org['other']))->getJson("/api/timeoff/requests/$id")->assertForbidden();
    }

    public function test_sick_leave_accepts_a_handover_too(): void
    {
        $sick = LeaveType::query()->where('code', 'sick')->firstOrFail();
        $this->file(['leave_type_id' => $sick->id, 'handover_to_employee_id' => $this->org['other']->id])->assertCreated()
            ->assertJsonPath('data.handover_to.id', $this->org['other']->id);
    }

    public function test_self_terminated_unknown_and_malformed_colleagues_are_rejected(): void
    {
        $this->file(['handover_to_employee_id' => $this->org['worker']->id])->assertStatus(422)->assertJsonPath('code', 'invalid_handover');
        $this->file(['handover_to_employee_id' => 999999])->assertStatus(422)->assertJsonPath('code', 'invalid_handover');
        $this->file(['handover_to_employee_id' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('handover_to_employee_id');

        $gone = $this->employee(['full_name' => 'Gone Person', 'status' => 'terminated', 'fired_at' => '2026-09-01']);
        $this->file(['handover_to_employee_id' => $gone->id])->assertStatus(422)->assertJsonPath('code', 'invalid_handover');
        // Not even HR may pick a terminated colleague (the picker without include_terminated).
        $this->actingAs($this->login(UserRole::Admin))->postJson('/api/timeoff/requests', $this->body([
            'employee_id' => $this->org['worker']->id, 'handover_to_employee_id' => $gone->id,
        ]))->assertStatus(422)->assertJsonPath('code', 'invalid_handover');
        $this->assertSame(0, LeaveRequest::query()->count());
    }

    public function test_a_manager_filing_for_a_subordinate_cannot_name_the_subordinate(): void
    {
        $this->actingAs($this->userOf($this->org['lead']))->postJson('/api/timeoff/requests', $this->body([
            'employee_id' => $this->org['worker']->id, 'handover_to_employee_id' => $this->org['worker']->id,
        ]))->assertStatus(422)->assertJsonPath('code', 'invalid_handover');
        $this->actingAs($this->userOf($this->org['lead']))->postJson('/api/timeoff/requests', $this->body([
            'employee_id' => $this->org['worker']->id, 'handover_to_employee_id' => $this->org['lead']->id,
        ]))->assertCreated()->assertJsonPath('data.handover_to.id', $this->org['lead']->id);
    }

    public function test_approval_creates_one_task_for_the_colleague_and_cancel_closes_it(): void
    {
        $peer = $this->userOf($this->org['peer']);
        $lead = $this->userOf($this->org['lead']);
        $id = $this->file(['handover_to_employee_id' => $this->org['peer']->id])->assertCreated()->json('data.id');
        $this->assertSame(0, Task::query()->count(), 'no task while the request is pending');

        $this->actingAs($lead)->postJson("/api/timeoff/requests/$id/approve")->assertOk();
        $task = Task::query()->sole();
        $this->assertSame($peer->id, $task->assignee_id);
        $this->assertSame('leave_handover', $task->type->value);
        $this->assertSame('Заміщення: Worker Person відсутній з 12.10.2026 по 16.10.2026', $task->title);
        $this->assertSame('2026-10-12', $task->due_at->toDateString());
        $this->assertSame('timeoff:handover:'.$id, $task->rule_key);
        $this->assertNull($task->done_at);
        $this->actingAs($peer)->getJson('/api/tasks?mine=1&source=timeoff')->assertOk()->assertJsonCount(1, 'data');

        // Repeated approval: 409, still one task; a repeated open() is idempotent too.
        $this->actingAs($lead)->postJson("/api/timeoff/requests/$id/approve")->assertStatus(409);
        $this->app->make(LeaveHandoverTasks::class)->open(LeaveRequest::query()->findOrFail($id));
        $this->assertSame(1, Task::query()->count());

        $this->actingAs($lead)->postJson("/api/timeoff/requests/$id/cancel")->assertOk();
        $this->assertNotNull($task->fresh()?->done_at);
    }

    public function test_rejection_leaves_no_open_task(): void
    {
        $id = $this->file(['handover_to_employee_id' => $this->org['peer']->id])->assertCreated()->json('data.id');
        $this->actingAs($this->userOf($this->org['lead']))->postJson("/api/timeoff/requests/$id/reject")->assertOk();
        $this->assertSame(0, Task::query()->whereNull('done_at')->count());
    }

    public function test_auto_approved_type_creates_the_task_at_once(): void
    {
        $sick = LeaveType::query()->where('code', 'sick')->firstOrFail();
        $sick->forceFill(['requires_approval' => false])->save();
        $this->file(['leave_type_id' => $sick->id, 'handover_to_employee_id' => $this->org['peer']->id])->assertCreated()
            ->assertJsonPath('data.status', 'approved');
        $this->assertSame($this->userOf($this->org['peer'])->id, Task::query()->sole()->assignee_id);
    }

    public function test_colleague_without_login_gets_no_task(): void
    {
        $noLogin = $this->employee(['full_name' => 'No Login Person']);
        $id = $this->file(['handover_to_employee_id' => $noLogin->id])->assertCreated()->json('data.id');
        $this->actingAs($this->userOf($this->org['lead']))->postJson("/api/timeoff/requests/$id/approve")->assertOk();
        $this->assertSame(0, Task::query()->count());
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return TestResponse<Response>
     */
    private function file(array $extra): TestResponse
    {
        return $this->actingAs($this->userOf($this->org['worker']))->postJson('/api/timeoff/requests', $this->body($extra));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function body(array $extra): array
    {
        return $extra + ['leave_type_id' => $this->vacation->id, 'starts_on' => '2026-10-12', 'ends_on' => '2026-10-16'];
    }
}
