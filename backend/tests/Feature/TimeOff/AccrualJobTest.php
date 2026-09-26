<?php

declare(strict_types=1);

namespace Tests\Feature\TimeOff;

use App\Modules\People\Models\Employee;
use App\Modules\TimeOff\Models\LeavePolicy;
use App\Modules\TimeOff\Models\LeaveType;
use App\Modules\TimeOff\Models\LedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\PeopleFixtures;
use Tests\TestCase;

/** "timeoff.accrue" through POST /api/ops/jobs/run (the cron). Synthetic data only. */
final class AccrualJobTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    private LeaveType $vacation;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ops.secret' => 'test-secret']);
        $this->vacation = LeaveType::query()->where('code', 'vacation')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_yearly_grant_is_idempotent_and_prorated_for_new_hires(): void
    {
        Carbon::setTestNow('2026-10-05 09:00:00');
        $old = $this->employee(['hired_at' => '2024-03-01']);
        $recent = $this->employee(['hired_at' => '2026-07-20']);
        $future = $this->employee(['hired_at' => '2027-02-01']);
        Employee::factory()->terminated()->create();

        $this->assertSame(['ok' => true, 'employees' => 3, 'accrued' => 2, 'expired' => 0], $this->runJobs());
        $this->assertSame(0, $this->runJobs()['accrued']);

        $this->assertSame(24.0, $this->balance($old));
        $this->assertSame(12.0, $this->balance($recent)); // July–December = 6/12 of 24
        $this->assertSame(0.0, $this->balance($future));
        $this->assertSame(2, LedgerEntry::query()->count());
    }

    public function test_monthly_policy_grants_once_per_month(): void
    {
        LeavePolicy::query()->update(['accrual_mode' => 'monthly', 'annual_days' => 18]);
        $employee = $this->employee(['hired_at' => '2025-01-15']);

        Carbon::setTestNow('2026-10-05 09:00:00');
        $this->runJobs();
        $this->runJobs();
        Carbon::setTestNow('2026-11-01 00:30:00');
        $this->runJobs();

        $this->assertSame(3.0, $this->balance($employee));
        $this->assertSame(['2026-10', '2026-11'], LedgerEntry::query()->orderBy('id')->pluck('period')->all());
    }

    public function test_unused_balance_above_carry_over_expires_on_jan_1(): void
    {
        LeavePolicy::query()->update(['carry_over_max' => 5]);
        $employee = $this->employee(['hired_at' => '2025-01-15']);

        Carbon::setTestNow('2026-06-01 09:00:00');
        $this->runJobs();
        $this->assertSame(24.0, $this->balance($employee));

        Carbon::setTestNow('2027-01-01 00:30:00');
        $this->assertSame(1, $this->runJobs()['expired']);
        $this->assertSame(0, $this->runJobs()['expired']);
        // 24 − 19 expired + 24 granted for 2027
        $this->assertSame(29.0, $this->balance($employee));
        $this->assertSame(-19.0, (float) LedgerEntry::query()->where('reason', 'expiry')->sole()->delta);
    }

    public function test_untracked_types_and_missing_policies_accrue_nothing(): void
    {
        Carbon::setTestNow('2026-10-05 09:00:00');
        LeavePolicy::query()->update(['active' => false]);
        $this->employee();

        $this->assertSame(0, $this->runJobs()['accrued']);
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    /**
     * One cron pass; returns the counters of "timeoff.accrue" (its name has a dot, so no assertJsonPath).
     *
     * @return array<string, mixed>
     */
    private function runJobs(): array
    {
        $jobs = $this->postJson('/api/ops/jobs/run', [], ['X-Ops-Secret' => 'test-secret'])->assertOk()->json('jobs');
        $this->assertIsArray($jobs);
        $this->assertIsArray($jobs['timeoff.accrue'] ?? null);

        return $jobs['timeoff.accrue'];
    }

    private function balance(Employee $employee): float
    {
        return round((float) LedgerEntry::query()->where('employee_id', $employee->id)->where('leave_type_id', $this->vacation->id)->sum('delta'), 2);
    }
}
