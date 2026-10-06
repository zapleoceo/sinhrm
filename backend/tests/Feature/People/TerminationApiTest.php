<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Auth\Enums\UserStatus;
use App\Modules\Directory\Models\Position;
use App\Modules\People\Events\EmployeeRestored;
use App\Modules\People\Events\EmployeeTerminated;
use App\Modules\People\Models\Employee;
use App\Modules\People\Services\ScheduledTerminationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\PeopleFixtures;
use Tests\TestCase;

/**
 * Termination from a date (scheduled, applied by the cron job on that date in Kyiv), cancellation, restore.
 * docs/modules/people.md, "Увольнение с даты и восстановление". Synthetic data only.
 */
final class TerminationApiTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ops.secret' => 'test-secret', 'app.user_timezone' => 'Europe/Kyiv', 'app.timezone' => 'UTC']);
        // Summer (Kyiv = UTC+3): 12:00 Kyiv on 2026-07-14.
        Carbon::setTestNow('2026-07-14 09:00:00');
        Event::fake([EmployeeTerminated::class, EmployeeRestored::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_who_may_terminate_hr_and_managers_up_the_chain(): void
    {
        $org = $this->org();
        $url = '/api/people/'.$org['worker']->id.'/terminate';
        $future = ['fired_at' => '2026-08-01'];

        // self, a peer, an unrelated person, a plain viewer: 403
        foreach ([$org['worker'], $org['peer'], $org['other']] as $who) {
            $this->actingAs($this->userOf($who))->postJson($url, $future)->assertForbidden()->assertJsonPath('code', 'forbidden');
        }
        $this->actingAs($this->login(UserRole::Viewer))->postJson($url, $future)->assertForbidden();
        // a manager two levels up (head → lead → worker) schedules; the profile flags it
        $this->actingAs($this->userOf($org['head']))->getJson('/api/people/'.$org['worker']->id)->assertJsonPath('data.access.terminate', true);
        $this->actingAs($this->userOf($org['head']))->postJson($url, $future)->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.fired_at', '2026-08-01')
            ->assertJsonPath('data.termination_scheduled', true);
        // the direct manager cancels, HR manager schedules again
        $this->actingAs($this->userOf($org['lead']))->postJson($url.'/cancel')->assertOk()->assertJsonPath('data.fired_at', null);
        $this->actingAs($this->login(UserRole::HrManager))->postJson($url, $future)->assertOk()->assertJsonPath('data.termination_scheduled', true);
        // an admin cannot terminate their own record
        $admin = $this->login(UserRole::Admin);
        $own = $this->employee([], $admin);
        $this->actingAs($admin)->getJson('/api/people/'.$own->id)->assertJsonPath('data.access.terminate', false);
        $this->actingAs($admin)->postJson('/api/people/'.$own->id.'/terminate', $future)->assertForbidden();
        Event::assertNotDispatched(EmployeeTerminated::class);
    }

    public function test_terminated_outside_the_callers_view_is_404_and_unknown_is_404(): void
    {
        $org = $this->org();
        $gone = Employee::factory()->terminated()->create(['manager_id' => $org['lead']->id]);

        $this->actingAs($this->userOf($org['other']))->postJson("/api/people/{$gone->id}/terminate", ['fired_at' => '2026-07-01'])->assertNotFound();
        $this->actingAs($this->userOf($org['other']))->postJson("/api/people/{$gone->id}/terminate/cancel")->assertNotFound();
        // visible to the manager above, but already terminated
        $this->actingAs($this->userOf($org['head']))->postJson("/api/people/{$gone->id}/terminate", ['fired_at' => '2026-07-01'])
            ->assertStatus(409)->assertJsonPath('code', 'already_terminated');
        $this->actingAs($this->login(UserRole::Admin))->postJson('/api/people/999999/terminate', ['fired_at' => '2026-07-01'])->assertNotFound();
        $this->actingAs($this->login(UserRole::Admin))->postJson('/api/people/999999/restore')->assertNotFound();
    }

    public function test_past_or_today_terminates_at_once_and_blocks_the_login(): void
    {
        $org = $this->org();
        $user = $this->userOf($org['worker']);
        $user->createToken('cli');

        $this->actingAs($this->userOf($org['lead']))->postJson('/api/people/'.$org['worker']->id.'/terminate', ['fired_at' => '2026-07-14', 'reason' => '  Own wish '])
            ->assertOk()
            ->assertJsonPath('data.status', 'terminated')
            ->assertJsonPath('data.termination_scheduled', false);

        $user->refresh();
        $this->assertSame(UserStatus::Blocked, $user->status);
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count());
        $this->assertSame($user->credential_version, $org['worker']->refresh()->termination_block_version);
        $this->assertSame('Own wish', $org['worker']->termination_reason);
        Event::assertDispatchedTimes(EmployeeTerminated::class, 1);
        // repeat → 409, nothing dispatched again; validation
        $url = '/api/people/'.$org['worker']->id.'/terminate';
        $this->actingAs($this->login(UserRole::Admin))->postJson($url, ['fired_at' => '2026-07-14'])->assertStatus(409)->assertJsonPath('code', 'already_terminated');
        $this->actingAs($this->login(UserRole::Admin))->postJson('/api/people/'.$org['peer']->id.'/terminate', ['fired_at' => '14.07.2026'])->assertUnprocessable()->assertJsonValidationErrors('fired_at');
        $this->actingAs($this->login(UserRole::Admin))->postJson('/api/people/'.$org['peer']->id.'/terminate', [])->assertUnprocessable()->assertJsonValidationErrors('fired_at');
        Event::assertDispatchedTimes(EmployeeTerminated::class, 1);
    }

    public function test_future_date_keeps_working_through_that_day_and_ends_after_it(): void
    {
        $org = $this->org();
        $worker = $org['worker'];
        $user = $this->userOf($worker);
        $this->actingAs($this->login(UserRole::Admin))->postJson("/api/people/{$worker->id}/terminate", ['fired_at' => '2026-07-20'])->assertOk()
            ->assertJsonPath('data.status', 'active');
        $this->actingAs($this->login(UserRole::Admin))->postJson("/api/people/{$worker->id}/terminate", ['fired_at' => '2026-07-25'])
            ->assertStatus(409)->assertJsonPath('code', 'termination_scheduled');
        $this->assertSame(UserStatus::Active, $user->refresh()->status);
        // still in the directory and the org chart before the date
        $this->actingAs($user)->getJson('/api/people?q=Worker')->assertOk()->assertJsonPath('meta.total', 1);

        // on day X itself (Kyiv) the person still works and keeps access
        Carbon::setTestNow('2026-07-20 12:00:00');
        $this->assertSame(0, $this->due());
        $this->assertFalse($worker->refresh()->isTerminated());
        $this->assertSame(UserStatus::Active, $user->refresh()->status);

        Carbon::setTestNow('2026-07-21 06:00:00');
        $this->assertSame(1, $this->due());
        $this->assertTrue($worker->refresh()->isTerminated());
        $this->assertSame(UserStatus::Blocked, $user->refresh()->status);
        $this->assertNotNull($worker->termination_block_version);
        // idempotent: the next cron run does nothing
        $this->assertSame(0, $this->due());
        Event::assertDispatchedTimes(EmployeeTerminated::class, 1);
    }

    /**
     * Access ends at the END of day X in Kyiv (owner, 2026-10-07): applied from 00:00 Kyiv of X+1. Day boundary by Kyiv
     * time, not UTC: 21:00-24:00 UTC (summer, +3) / 22:00-24:00 UTC (winter, +2) is already the next day in Kyiv.
     */
    public function test_the_day_boundary_follows_kyiv_not_utc(): void
    {
        $org = $this->org();
        $admin = $this->login(UserRole::Admin);
        $this->actingAs($admin)->postJson('/api/people/'.$org['worker']->id.'/terminate', ['fired_at' => '2026-07-15'])->assertOk();

        Carbon::setTestNow('2026-07-14 21:00:00'); // 00:00 Kyiv on X = the 15th: still active all day X
        $this->assertSame(0, $this->due());
        Carbon::setTestNow('2026-07-15 20:59:59'); // 23:59:59 Kyiv on X
        $this->assertSame(0, $this->due());
        $this->assertFalse($org['worker']->refresh()->isTerminated());
        Carbon::setTestNow('2026-07-15 21:00:00'); // 00:00 Kyiv on X+1, UTC date is still X
        $this->assertSame(1, $this->due());
        $this->assertSame(0, $this->due(), 'idempotent');

        // the request itself: at 23:30 UTC "today" is the next Kyiv day → applied at once; the day after → scheduled
        Carbon::setTestNow('2026-07-15 23:30:00');
        $this->actingAs($admin)->postJson('/api/people/'.$org['peer']->id.'/terminate', ['fired_at' => '2026-07-16'])->assertOk()
            ->assertJsonPath('data.status', 'terminated');
        $this->actingAs($admin)->postJson('/api/people/'.$org['other']->id.'/terminate', ['fired_at' => '2026-07-17'])->assertOk()
            ->assertJsonPath('data.status', 'active');

        // winter (UTC+2), X = 2027-01-15: 21:59 UTC on X is still X in Kyiv, 22:00 UTC is X+1
        $this->actingAs($admin)->postJson('/api/people/'.$org['lead']->id.'/terminate', ['fired_at' => '2027-01-15'])->assertOk();
        Carbon::setTestNow('2027-01-15 21:59:00');
        $this->assertSame(1, $this->due()); // only "other" (X = 2026-07-17, long over)
        $this->assertFalse($org['lead']->refresh()->isTerminated());
        Carbon::setTestNow('2027-01-15 22:00:00');
        $this->assertSame(1, $this->due());
        $this->assertSame(0, $this->due(), 'idempotent');
        $this->assertTrue($org['lead']->refresh()->isTerminated());
    }

    public function test_cancel_before_the_date_with_audit(): void
    {
        $org = $this->org();
        $lead = $this->userOf($org['lead']);
        $url = '/api/people/'.$org['worker']->id.'/terminate';

        $this->actingAs($lead)->postJson($url.'/cancel')->assertStatus(409)->assertJsonPath('code', 'termination_not_scheduled');
        $this->actingAs($lead)->postJson($url, ['fired_at' => '2026-07-30', 'reason' => 'Relocation'])->assertOk();
        $this->actingAs($this->userOf($org['peer']))->postJson($url.'/cancel')->assertForbidden();
        $this->actingAs($lead)->postJson($url.'/cancel')->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.fired_at', null)
            ->assertJsonPath('data.termination_scheduled', false);
        $this->assertNull($org['worker']->refresh()->termination_reason);
        $audited = DB::table('audit_log')->where('entity_type', 'employee')->where('entity_id', $org['worker']->id)
            ->where('user_id', $lead->id)->pluck('changes')
            ->filter(static fn (mixed $changes): bool => is_string($changes) && str_contains($changes, 'fired_at'))->count();
        $this->assertGreaterThanOrEqual(2, $audited, 'schedule and cancel are both audited');

        Carbon::setTestNow('2026-08-01 12:00:00');
        $this->assertSame(0, $this->due());
        $this->assertFalse($org['worker']->refresh()->isTerminated());
        Event::assertNotDispatched(EmployeeTerminated::class);
    }

    public function test_restore_into_the_old_or_a_new_position_unblocks_only_our_block(): void
    {
        $org = $this->org();
        $hr = $this->login(UserRole::HrManager);
        $position = Position::factory()->create();
        $worker = $org['worker'];
        $this->actingAs($hr)->postJson("/api/people/{$worker->id}/terminate", ['fired_at' => '2026-07-01'])->assertOk();
        $user = $this->userOf($worker);
        $this->assertSame(UserStatus::Blocked, $user->status);

        // only HR restores; a manager above cannot
        $this->actingAs($this->userOf($org['lead']))->postJson("/api/people/{$worker->id}/restore")->assertForbidden();
        // validation: unknown position, bad date, a manager that is the employee
        $this->actingAs($hr)->postJson("/api/people/{$worker->id}/restore", ['position_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors('position_id');
        $this->actingAs($hr)->postJson("/api/people/{$worker->id}/restore", ['hired_at' => '01.08.2026'])->assertUnprocessable()->assertJsonValidationErrors('hired_at');
        // a terminated manager (here: the employee itself) is rejected by validation
        $this->actingAs($hr)->postJson("/api/people/{$worker->id}/restore", ['manager_id' => (string) $worker->id])->assertUnprocessable()->assertJsonValidationErrors('manager_id');
        // someone below the restored person → manager_cycle (lead's report peer cannot become lead's manager)
        $this->actingAs($hr)->postJson('/api/people/'.$org['lead']->id.'/terminate', ['fired_at' => '2026-07-01'])->assertOk();
        $this->actingAs($hr)->postJson('/api/people/'.$org['lead']->id.'/restore', ['manager_id' => $org['peer']->id])->assertUnprocessable()->assertJsonPath('code', 'manager_cycle');

        // new position, string ids are fine
        $this->actingAs($hr)->postJson("/api/people/{$worker->id}/restore", [
            'position_id' => (string) $position->id,
            'manager_id' => (string) $org['head']->id,
            'hired_at' => '2026-07-15',
        ])->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.fired_at', null)
            ->assertJsonPath('data.position.id', $position->id)
            ->assertJsonPath('data.manager_id', $org['head']->id)
            ->assertJsonPath('data.hired_at', '2026-07-15');
        $this->assertSame(UserStatus::Active, $user->refresh()->status);
        $this->assertNull($worker->refresh()->termination_block_version);
        Event::assertDispatchedTimes(EmployeeRestored::class, 1);
        // not terminated any more → 409
        $this->actingAs($hr)->postJson("/api/people/{$worker->id}/restore")->assertStatus(409)->assertJsonPath('code', 'not_terminated');
    }

    public function test_restore_never_lifts_a_manual_block(): void
    {
        $org = $this->org();
        $admin = $this->login(UserRole::Admin);
        // blocked by hand before the termination: termination does not block, restore does not unblock
        $before = $this->userOf($org['peer']);
        $before->forceFill(['status' => UserStatus::Blocked])->save();
        $this->actingAs($admin)->postJson('/api/people/'.$org['peer']->id.'/terminate', ['fired_at' => '2026-07-01'])->assertOk();
        $this->assertNull($org['peer']->refresh()->termination_block_version);
        $this->actingAs($admin)->postJson('/api/people/'.$org['peer']->id.'/restore')->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertSame(UserStatus::Blocked, $before->refresh()->status);

        // blocked again by hand after the termination (credential_version moved on): stays blocked
        $this->actingAs($admin)->postJson('/api/people/'.$org['worker']->id.'/terminate', ['fired_at' => '2026-07-01'])->assertOk();
        $after = $this->userOf($org['worker']);
        $after->forceFill(['credential_version' => $after->credential_version + 1])->save();
        $this->actingAs($admin)->postJson('/api/people/'.$org['worker']->id.'/restore')->assertOk();
        $this->assertSame(UserStatus::Blocked, $after->refresh()->status);
    }

    public function test_restore_refuses_anonymized_records(): void
    {
        $gone = Employee::factory()->terminated()->create();
        $gone->forceFill(['anonymized_at' => Carbon::now()])->save();

        $this->actingAs($this->login(UserRole::Admin))->postJson("/api/people/{$gone->id}/restore")->assertStatus(409)->assertJsonPath('code', 'anonymized');
    }

    public function test_the_job_runs_from_the_cron_endpoint(): void
    {
        $org = $this->org();
        $this->actingAs($this->login(UserRole::Admin))->postJson('/api/people/'.$org['worker']->id.'/terminate', ['fired_at' => '2026-07-15'])->assertOk();
        Carbon::setTestNow('2026-07-15 21:00:00'); // 00:00 Kyiv on the 16th: day X (15th) is over

        $jobs = $this->postJson('/api/ops/jobs/run', [], ['X-Ops-Secret' => 'test-secret'])->assertOk()->json('jobs');
        $this->assertIsArray($jobs);
        $this->assertSame(['ok' => true, 'people_terminated' => 1], $jobs['people.terminations']);
        $this->assertTrue($org['worker']->refresh()->isTerminated());
    }

    /** One cron pass of the scheduled terminations (the job alone, at the frozen "now"). */
    private function due(): int
    {
        $result = $this->app->make(ScheduledTerminationJob::class)->run(Carbon::now());

        return (int) $result['people_terminated'];
    }
}
