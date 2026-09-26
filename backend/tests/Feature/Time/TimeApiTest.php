<?php

declare(strict_types=1);

namespace Tests\Feature\Time;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Scripts\Models\Task;
use App\Modules\Time\Models\Timesheet;
use App\Modules\TimeOff\Models\Holiday;
use App\Modules\TimeOff\Models\LeaveRequest;
use App\Modules\TimeOff\Models\LeaveType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\PeopleFixtures;
use Tests\TestCase;

/**
 * Time: authz matrix (own / manager subtree / admin), overtime vs schedule, TimeOff leave and holidays as absence,
 * submit/approve, Friday reminders idempotency, schedules, reports. Week of 2026-10-05 (Mon) … 2026-10-11 (Sun).
 */
final class TimeApiTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    private const string WEEK = '2026-10-05';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-07 10:00:00'); // Wednesday
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return list<array{date: string, hours: float}> */
    private function fullWeek(float $hours = 8.0): array
    {
        return array_map(static fn (int $d): array => ['date' => Carbon::parse(self::WEEK)->addDays($d)->toDateString(), 'hours' => $hours], range(0, 4));
    }

    public function test_authz_matrix(): void
    {
        $this->getJson('/api/time/week')->assertUnauthorized();
        $org = $this->org();
        $worker = $this->userOf($org['worker']);
        $lead = $this->userOf($org['lead']);
        $peer = $this->userOf($org['peer']);
        $admin = $this->login(UserRole::Admin);
        $wid = $org['worker']->id;

        $this->actingAs($worker)->getJson('/api/time/week?week='.self::WEEK)->assertOk()
            ->assertJsonPath('data.employee.id', $wid)->assertJsonPath('data.status', 'draft')->assertJsonPath('data.can.edit', true)
            ->assertJsonPath('data.totals.expected', 40)->assertJsonPath('data.schedule.source', 'company');
        $this->actingAs($lead)->getJson("/api/time/week?employee_id=$wid")->assertOk()->assertJsonPath('data.can.edit', false);
        $this->actingAs($this->userOf($org['head']))->getJson("/api/time/week?employee_id=$wid")->assertOk();
        $this->actingAs($peer)->getJson("/api/time/week?employee_id=$wid")->assertNotFound();
        $this->actingAs($lead)->putJson('/api/time/week', ['week' => self::WEEK, 'employee_id' => $wid, 'entries' => $this->fullWeek()])->assertForbidden();
        $this->actingAs($admin)->putJson('/api/time/week', ['week' => self::WEEK, 'employee_id' => $wid, 'entries' => [['date' => self::WEEK, 'hours' => 1]]])->assertOk();
        $this->actingAs($this->login())->getJson('/api/time/week')->assertUnprocessable()->assertJsonPath('code', 'no_employee');
        // Schedules: read by all, changed by admins.
        $this->actingAs($worker)->getJson('/api/time/schedules')->assertOk()->assertJsonPath('data.0.hours_per_day', 8);
        $this->actingAs($worker)->putJson('/api/time/schedules', ['days' => [1, 2], 'hours_per_day' => 4])->assertForbidden();
        // Team: managers see their subtree, others nothing.
        $this->actingAs($lead)->getJson('/api/time/team?week='.self::WEEK)->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($worker)->getJson('/api/time/team')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($admin)->getJson('/api/time/team')->assertOk()->assertJsonCount(5, 'data');
    }

    public function test_overtime_validation_submit_and_decisions(): void
    {
        $org = $this->org();
        $worker = $this->userOf($org['worker']);
        $lead = $this->userOf($org['lead']);
        $entries = $this->fullWeek(9);
        $entries[] = ['date' => '2026-10-10', 'hours' => 2.5, 'project' => 'Synthetic project', 'category' => 'support', 'note' => 'Saturday'];

        $this->actingAs($worker)->putJson('/api/time/week', ['week' => self::WEEK, 'entries' => [['date' => '2026-10-12', 'hours' => 1]]])
            ->assertUnprocessable()->assertJsonPath('code', 'outside_week');
        $this->actingAs($worker)->putJson('/api/time/week', ['week' => self::WEEK, 'entries' => [['date' => self::WEEK, 'hours' => 20], ['date' => self::WEEK, 'hours' => 5]]])
            ->assertUnprocessable()->assertJsonPath('code', 'day_overflow');
        $this->actingAs($worker)->putJson('/api/time/week', ['week' => '2026-10-08', 'entries' => $entries])->assertOk()
            ->assertJsonPath('data.week_start', self::WEEK)
            ->assertJsonPath('data.totals.worked', 47.5)->assertJsonPath('data.totals.overtime', 7.5)->assertJsonPath('data.totals.missing', 0)
            ->assertJsonPath('data.entries.5.project', 'Synthetic project')->assertJsonPath('data.days.5.worked', 2.5);

        $id = $this->actingAs($worker)->postJson('/api/time/week/submit', ['week' => self::WEEK])->assertOk()->assertJsonPath('data.status', 'submitted')->json('data.timesheet_id');
        $sheet = Timesheet::query()->findOrFail($id);
        $this->assertEquals(47.5, (float) $sheet->worked_hours);
        $this->assertEquals(7.5, (float) $sheet->overtime_hours);
        $this->actingAs($worker)->putJson('/api/time/week', ['week' => self::WEEK, 'entries' => []])->assertStatus(409)->assertJsonPath('code', 'not_editable');

        // Decisions: the manager (not the employee, not a colleague); reject needs a comment and reopens the week.
        $this->actingAs($lead)->getJson('/api/time/approvals')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.overtime', 7.5);
        $this->actingAs($worker)->getJson('/api/time/approvals')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($worker)->postJson("/api/time/timesheets/$id/decision", ['decision' => 'approve'])->assertForbidden();
        $this->actingAs($this->userOf($org['peer']))->postJson("/api/time/timesheets/$id/decision", ['decision' => 'approve'])->assertNotFound();
        $this->actingAs($lead)->postJson("/api/time/timesheets/$id/decision", ['decision' => 'reject'])->assertUnprocessable();
        $this->actingAs($lead)->postJson("/api/time/timesheets/$id/decision", ['decision' => 'reject', 'comment' => 'Split the Saturday'])->assertOk()
            ->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.decision_comment', 'Split the Saturday');
        $this->actingAs($worker)->putJson('/api/time/week', ['week' => self::WEEK, 'entries' => $this->fullWeek()])->assertOk()->assertJsonPath('data.status', 'draft');
        $this->actingAs($worker)->postJson('/api/time/week/submit', ['week' => self::WEEK])->assertOk();
        $this->actingAs($this->userOf($org['head']))->postJson("/api/time/timesheets/$id/decision", ['decision' => 'approve'])->assertOk()
            ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.totals.overtime', 0);
        $this->actingAs($lead)->postJson("/api/time/timesheets/$id/decision", ['decision' => 'approve'])->assertStatus(409);
        $this->actingAs($lead)->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.time.my_approvals.count', 0);
    }

    public function test_leave_holidays_and_schedules(): void
    {
        $branch = Branch::factory()->create();
        $worker = $this->employee(['full_name' => 'Leave Person', 'branch_id' => $branch->id], $user = $this->login());
        $vacation = LeaveType::query()->where('code', 'vacation')->firstOrFail();
        // Approved: Wednesday full day; Thursday half (end); a pending request is ignored.
        LeaveRequest::query()->create(['employee_id' => $worker->id, 'leave_type_id' => $vacation->id, 'starts_on' => '2026-10-07', 'ends_on' => '2026-10-08', 'half_day' => 'end', 'days' => 1.5, 'status' => 'approved']);
        LeaveRequest::query()->create(['employee_id' => $worker->id, 'leave_type_id' => $vacation->id, 'starts_on' => '2026-10-09', 'ends_on' => '2026-10-09', 'days' => 1, 'status' => 'pending']);
        Holiday::query()->create(['date' => '2026-10-05', 'name' => 'Synthetic holiday']);

        $week = $this->actingAs($user)->getJson('/api/time/week?week='.self::WEEK)->assertOk();
        // 40 − Monday holiday 8 − Wednesday 8 − Thursday half 4 = 20 expected; absence 12.
        $week->assertJsonPath('data.totals.expected', 20)->assertJsonPath('data.totals.absence', 12)->assertJsonPath('data.totals.missing', 20)
            ->assertJsonPath('data.days.0.holiday', true)->assertJsonPath('data.days.2.leave.type', 'Vacation')->assertJsonPath('data.days.3.leave.fraction', 0.5)
            ->assertJsonPath('data.days.3.expected', 4);
        $this->actingAs($user)->putJson('/api/time/week', ['week' => self::WEEK, 'entries' => [
            ['date' => '2026-10-06', 'hours' => 8], ['date' => '2026-10-08', 'hours' => 4], ['date' => '2026-10-09', 'hours' => 8],
        ]])->assertOk()->assertJsonPath('data.totals.missing', 0)->assertJsonPath('data.totals.overtime', 0);

        // Branch schedule (4 days × 10 h) beats the company default; the employee's own schedule beats both.
        $admin = $this->login(UserRole::Admin);
        $this->actingAs($admin)->putJson('/api/time/schedules', ['branch_id' => $branch->id, 'days' => [4, 1, 2, 3], 'hours_per_day' => 10])->assertOk()
            ->assertJsonPath('data.days', [1, 2, 3, 4]);
        $this->actingAs($user)->getJson('/api/time/week?week=2026-10-12')->assertJsonPath('data.schedule.source', 'branch')->assertJsonPath('data.totals.expected', 40)
            ->assertJsonPath('data.days.4.expected', 0);
        $worker->update(['work_schedule' => ['days' => [1, 2, 3], 'hours_per_day' => 6]]);
        $this->actingAs($user)->getJson('/api/time/week?week=2026-10-12')->assertJsonPath('data.schedule.source', 'employee')->assertJsonPath('data.totals.expected', 18);
        $this->actingAs($admin)->deleteJson("/api/time/schedules/{$branch->id}")->assertNoContent();
    }

    public function test_friday_reminders_are_idempotent_per_week(): void
    {
        $org = $this->org();
        $this->employee(['full_name' => 'No Login']);
        $done = $org['peer'];
        $this->actingAs($this->userOf($done))->putJson('/api/time/week', ['week' => self::WEEK, 'entries' => $this->fullWeek()])->assertOk();
        $this->actingAs($this->userOf($done))->postJson('/api/time/week/submit', ['week' => self::WEEK])->assertOk();

        $this->assertSame('not_friday', $this->reminders()['time_skipped'], 'Wednesday: nothing');
        Carbon::setTestNow('2026-10-09 15:00:00');
        $this->assertSame(4, $this->reminders()['time_reminders'], 'head, lead, worker, other — not the submitted peer, not the employee without login');
        Carbon::setTestNow('2026-10-10 11:00:00');
        $this->reminders();
        $this->assertSame(4, Task::query()->where('type', 'timesheet_reminder')->count(), 'one task per employee and week');
        $task = Task::query()->where('employee_id', $org['worker']->id)->where('type', 'timesheet_reminder')->firstOrFail();
        $this->assertSame('/time?week='.self::WEEK, $task->link);

        // Submitting closes the reminder.
        $this->actingAs($this->userOf($org['worker']))->postJson('/api/time/week/submit', ['week' => self::WEEK])->assertOk();
        $this->assertNotNull($task->fresh()?->done_at);
        // The next week gets its own reminder.
        Carbon::setTestNow('2026-10-16 09:00:00');
        $this->reminders();
        $this->assertSame(2, Task::query()->where('employee_id', $org['worker']->id)->where('type', 'timesheet_reminder')->count());
    }

    public function test_reports_follow_the_people_scope(): void
    {
        $org = $this->org();
        $admin = $this->login(UserRole::Admin);
        $this->actingAs($this->userOf($org['worker']))->putJson('/api/time/week', ['week' => self::WEEK, 'entries' => $this->fullWeek(10)])->assertOk();

        $byEmployee = $this->rowsBy($this->actingAs($admin)->getJson('/api/reports/catalog/time_by_employee?from='.self::WEEK.'&to=2026-10-11')->assertOk(), 'employee');
        $this->assertEquals(50.0, $byEmployee['Worker Person']['worked']);
        $this->assertEquals(10.0, $byEmployee['Worker Person']['overtime']);
        $this->assertEquals(40.0, $byEmployee['Peer Person']['missing']);
        $overtime = $this->actingAs($admin)->getJson('/api/reports/catalog/time_overtime?from='.self::WEEK.'&to=2026-10-11')->assertOk()->json('data.rows');
        $this->assertSame(['Worker Person'], array_column($overtime, 'employee'));
        $missing = $this->actingAs($admin)->getJson('/api/reports/catalog/time_missing?from='.self::WEEK.'&to=2026-10-11')->assertOk()->json('data.rows');
        $this->assertCount(4, $missing, 'the worker has hours and no missing; four others miss the whole week');
        $this->actingAs($admin)->getJson('/api/reports/catalog/time_by_department?from='.self::WEEK.'&to=2026-10-11')->assertOk();

        // The lead sees self + subtree (lead, worker, peer); a plain employee has no team reports.
        $lead = $this->actingAs($this->userOf($org['lead']))->getJson('/api/reports/catalog/time_by_employee?from='.self::WEEK.'&to=2026-10-11')->assertOk()->json('data.rows');
        $this->assertEqualsCanonicalizing(['Lead Person', 'Worker Person', 'Peer Person'], array_column($lead, 'employee'));
        $this->actingAs($this->userOf($org['worker']))->getJson('/api/reports/catalog/time_by_employee')->assertNotFound();
    }

    /** @return array<string, mixed> */
    private function reminders(): array
    {
        config(['ops.secret' => 'synthetic-ops-secret']);
        $jobs = $this->postJson('/api/ops/jobs/run', [], ['X-Ops-Secret' => 'synthetic-ops-secret'])->assertOk()->json('jobs');
        $this->assertIsArray($jobs);
        $this->assertTrue($jobs['time.reminders']['ok']);

        return $jobs['time.reminders'];
    }

    /**
     * Report rows keyed by a column.
     *
     * @param  TestResponse<Response>  $response
     * @return array<string, array<string, mixed>>
     */
    private function rowsBy(TestResponse $response, string $key): array
    {
        $rows = $response->json('data.rows');
        $this->assertIsArray($rows);
        $out = [];
        foreach ($rows as $row) {
            $this->assertIsArray($row);
            $out[(string) $row[$key]] = $row;
        }

        return $out;
    }
}
