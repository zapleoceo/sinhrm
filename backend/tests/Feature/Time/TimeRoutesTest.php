<?php

declare(strict_types=1);

namespace Tests\Feature\Time;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Time\Models\Timesheet;
use App\Modules\TimeOff\Models\Holiday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PeopleFixtures;
use Tests\TestCase;

/**
 * Time — every route: 401 for guests, 403 per global role on the schedule gate, 422 validation (incl. query/body
 * values arriving as strings), 404 for invisible/unknown ids, overtime math on weekend + holiday, manager-subtree approval.
 */
final class TimeRoutesTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    private const string WEEK = '2026-10-05';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-07 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return iterable<string, array{string, string}> */
    public static function routes(): iterable
    {
        yield 'week' => ['GET', '/api/time/week'];
        yield 'save' => ['PUT', '/api/time/week'];
        yield 'submit' => ['POST', '/api/time/week/submit'];
        yield 'decide' => ['POST', '/api/time/timesheets/1/decision'];
        yield 'approvals' => ['GET', '/api/time/approvals'];
        yield 'team' => ['GET', '/api/time/team'];
        yield 'schedules' => ['GET', '/api/time/schedules'];
        yield 'schedules.save' => ['PUT', '/api/time/schedules'];
        yield 'schedules.delete' => ['DELETE', '/api/time/schedules/1'];
    }

    #[DataProvider('routes')]
    public function test_guest_gets_401(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertUnauthorized();
    }

    #[DataProvider('routes')]
    public function test_blocked_user_gets_403(string $method, string $uri): void
    {
        $this->actingAs(User::factory()->blocked()->withRole(UserRole::Admin)->create())->json($method, $uri)->assertForbidden();
    }

    /** @return iterable<string, array{UserRole, bool}> */
    public static function scheduleRoles(): iterable
    {
        yield 'superadmin' => [UserRole::Superadmin, true];
        yield 'admin' => [UserRole::Admin, true];
        yield 'hr_manager' => [UserRole::HrManager, true];
        yield 'recruiter' => [UserRole::Recruiter, false];
        yield 'employee' => [UserRole::Employee, false];
        yield 'viewer' => [UserRole::Viewer, false];
    }

    #[DataProvider('scheduleRoles')]
    public function test_schedule_writes_follow_the_time_manage_gate(UserRole $role, bool $allowed): void
    {
        $branch = Branch::factory()->create();
        $user = $this->login($role);
        $this->actingAs($user)->getJson('/api/time/schedules')->assertOk();
        $save = $this->actingAs($user)->putJson('/api/time/schedules', ['branch_id' => (string) $branch->id, 'days' => ['1', '2'], 'hours_per_day' => '7.5']);
        $delete = $this->actingAs($user)->deleteJson("/api/time/schedules/{$branch->id}");
        if ($allowed) {
            $save->assertOk()->assertJsonPath('data.days', [1, 2])->assertJsonPath('data.hours_per_day', 7.5)->assertJsonPath('data.branch.id', $branch->id);
            $delete->assertNoContent();
            $this->assertDatabaseMissing('work_schedules', ['branch_id' => $branch->id]);
        } else {
            $save->assertForbidden();
            $delete->assertForbidden();
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidSchedules(): iterable
    {
        yield 'day out of range' => [['days' => [8], 'hours_per_day' => 8], 'days.0'];
        yield 'duplicate day' => [['days' => [1, 1], 'hours_per_day' => 8], 'days.0'];
        yield 'too many hours' => [['days' => [1], 'hours_per_day' => 25], 'hours_per_day'];
        yield 'missing hours' => [['days' => [1]], 'hours_per_day'];
        yield 'days missing' => [['hours_per_day' => 8], 'days'];
        yield 'unknown branch' => [['branch_id' => 999999, 'days' => [1], 'hours_per_day' => 8], 'branch_id'];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidSchedules')]
    public function test_schedule_validation(array $body, string $field): void
    {
        $this->actingAs($this->login(UserRole::Admin))->putJson('/api/time/schedules', $body)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_week_query_arrives_as_strings(): void
    {
        $org = $this->org();
        $lead = $this->userOf($org['lead']);
        $wid = (string) $org['worker']->id;

        $this->actingAs($lead)->getJson("/api/time/week?week=2026-10-09&employee_id=$wid")->assertOk()
            ->assertJsonPath('data.employee.id', $org['worker']->id)->assertJsonPath('data.week_start', self::WEEK);
        $this->actingAs($lead)->getJson('/api/time/week?employee_id=abc')->assertUnprocessable()->assertJsonValidationErrors('employee_id');
        $this->actingAs($lead)->getJson('/api/time/week?week=09.10.2026')->assertUnprocessable()->assertJsonValidationErrors('week');
        $this->actingAs($lead)->getJson('/api/time/week?employee_id=0')->assertUnprocessable();
        $this->actingAs($lead)->getJson('/api/time/week?employee_id=999999')->assertNotFound();
        $this->actingAs($lead)->getJson('/api/time/team?branch_id=x')->assertUnprocessable()->assertJsonValidationErrors('branch_id');
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidEntries(): iterable
    {
        yield 'entries absent' => [['week' => self::WEEK], 'entries'];
        yield 'zero hours' => [['week' => self::WEEK, 'entries' => [['date' => self::WEEK, 'hours' => 0]]], 'entries.0.hours'];
        yield 'over 24 in one row' => [['week' => self::WEEK, 'entries' => [['date' => self::WEEK, 'hours' => 24.5]]], 'entries.0.hours'];
        yield 'non numeric hours' => [['week' => self::WEEK, 'entries' => [['date' => self::WEEK, 'hours' => 'eight']]], 'entries.0.hours'];
        yield 'bad date' => [['week' => self::WEEK, 'entries' => [['date' => '05/10/2026', 'hours' => 1]]], 'entries.0.date'];
        yield 'project too long' => [['week' => self::WEEK, 'entries' => [['date' => self::WEEK, 'hours' => 1, 'project' => str_repeat('p', 121)]]], 'entries.0.project'];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidEntries')]
    public function test_save_validation(array $body, string $field): void
    {
        $this->employee([], $user = $this->login(UserRole::Employee));
        $this->actingAs($user)->putJson('/api/time/week', $body)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_hours_as_strings_and_overtime_on_weekend_and_holiday(): void
    {
        $this->employee([], $user = $this->login(UserRole::Employee));
        Holiday::query()->create(['date' => '2026-10-06', 'name' => 'Synthetic holiday']);
        // Expected: 40 − 8 (Tuesday holiday) = 32. Worked: Mon 8, Tue (holiday) 3, Wed–Fri 8×3, Sun 2 = 37 → overtime 5.
        $entries = [
            ['date' => '2026-10-05', 'hours' => '8'], ['date' => '2026-10-06', 'hours' => '3'],
            ['date' => '2026-10-07', 'hours' => '8'], ['date' => '2026-10-08', 'hours' => '8'],
            ['date' => '2026-10-09', 'hours' => '8'], ['date' => '2026-10-11', 'hours' => '2'],
        ];
        $this->actingAs($user)->putJson('/api/time/week', ['week' => self::WEEK, 'entries' => $entries])->assertOk()
            ->assertJsonPath('data.totals.expected', 32)->assertJsonPath('data.totals.worked', 37)
            ->assertJsonPath('data.totals.overtime', 5)->assertJsonPath('data.totals.missing', 0);

        // Under-filled week: overtime never negative, missing = expected − worked.
        $this->actingAs($user)->putJson('/api/time/week', ['week' => self::WEEK, 'entries' => [['date' => '2026-10-05', 'hours' => '4.25']]])->assertOk()
            ->assertJsonPath('data.totals.overtime', 0)->assertJsonPath('data.totals.missing', 27.75);
    }

    public function test_submit_twice_is_409_and_submit_foreign_week_is_404_or_403(): void
    {
        $org = $this->org();
        $worker = $this->userOf($org['worker']);
        $this->actingAs($worker)->postJson('/api/time/week/submit', ['week' => self::WEEK])->assertOk();
        $this->actingAs($worker)->postJson('/api/time/week/submit', ['week' => self::WEEK])->assertStatus(409);
        // Manager sees the subtree, but only the employee (or HR) submits.
        $this->actingAs($this->userOf($org['lead']))->postJson('/api/time/week/submit', ['week' => self::WEEK, 'employee_id' => (string) $org['peer']->id])->assertForbidden();
        $this->actingAs($this->userOf($org['other']))->postJson('/api/time/week/submit', ['week' => self::WEEK, 'employee_id' => $org['peer']->id])->assertNotFound();
        $this->actingAs($this->login(UserRole::HrManager))->postJson('/api/time/week/submit', ['week' => self::WEEK, 'employee_id' => $org['peer']->id])->assertOk()
            ->assertJsonPath('data.status', 'submitted');
    }

    public function test_decision_rules(): void
    {
        $org = $this->org();
        $worker = $this->userOf($org['worker']);
        $id = $this->actingAs($worker)->postJson('/api/time/week/submit', ['week' => self::WEEK])->assertOk()->json('data.timesheet_id');
        $lead = $this->userOf($org['lead']);

        $this->actingAs($lead)->postJson('/api/time/timesheets/999999/decision', ['decision' => 'approve'])->assertNotFound();
        $this->actingAs($lead)->postJson("/api/time/timesheets/$id/decision", ['decision' => 'maybe'])->assertUnprocessable()->assertJsonValidationErrors('decision');
        $this->actingAs($lead)->postJson("/api/time/timesheets/$id/decision", [])->assertUnprocessable()->assertJsonValidationErrors('decision');
        $this->actingAs($lead)->postJson("/api/time/timesheets/$id/decision", ['decision' => 'reject', 'comment' => str_repeat('c', 2001)])->assertUnprocessable();
        // Plain roles outside the subtree do not even see it.
        foreach ([UserRole::Recruiter, UserRole::Employee, UserRole::Viewer] as $role) {
            $this->actingAs($this->login($role))->postJson("/api/time/timesheets/$id/decision", ['decision' => 'approve'])->assertNotFound();
        }
        $this->actingAs($this->login(UserRole::HrManager))->postJson("/api/time/timesheets/$id/decision", ['decision' => 'approve'])->assertOk()
            ->assertJsonPath('data.status', 'approved');
        $this->assertSame('approved', Timesheet::query()->findOrFail($id)->status->value);
    }

    public function test_approvals_exclude_own_week_of_a_manager(): void
    {
        $org = $this->org();
        $lead = $this->userOf($org['lead']);
        $this->actingAs($lead)->postJson('/api/time/week/submit', ['week' => self::WEEK])->assertOk();
        $this->actingAs($this->userOf($org['worker']))->postJson('/api/time/week/submit', ['week' => self::WEEK])->assertOk();

        $rows = $this->actingAs($lead)->getJson('/api/time/approvals')->assertOk()->json('data');
        $this->assertSame([$org['worker']->id], array_column(array_column($rows, 'employee'), 'id'));
        $headRows = $this->actingAs($this->userOf($org['head']))->getJson('/api/time/approvals')->assertOk()->json('data');
        $this->assertCount(2, $headRows, 'the head sees lead and worker (whole subtree)');
        $this->actingAs($this->login(UserRole::Viewer))->getJson('/api/time/approvals')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_team_filters_by_branch_as_string(): void
    {
        $branch = Branch::factory()->create();
        $this->employee(['branch_id' => $branch->id]);
        $this->employee();
        $admin = $this->login(UserRole::Admin);
        $this->actingAs($admin)->getJson('/api/time/team?week='.self::WEEK.'&branch_id='.$branch->id)->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($admin)->getJson('/api/time/team')->assertOk()->assertJsonCount(2, 'data');
    }
}
