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
        LeavePolicy::query()->update(['accrual_mode' => 'monthly', 'annual_days' => 20]);
        $employee = $this->employee(['hired_at' => '2025-01-15']);

        Carbon::setTestNow('2026-10-05 09:00:00');
        $this->runJobs();
        $this->runJobs();
        Carbon::setTestNow('2026-11-01 00:30:00');
        $this->runJobs();

        // October catches up Jan..Oct (20 × 10/12 = 16.67), November adds the next step to the cumulative 18.33
        $this->assertSame(18.33, $this->balance($employee));
        $this->assertSame(['2026-10', '2026-11'], LedgerEntry::query()->orderBy('id')->pluck('period')->all());
    }

    public function test_a_year_of_monthly_runs_sums_exactly_to_the_annual_norm(): void
    {
        LeavePolicy::query()->update(['accrual_mode' => 'monthly', 'annual_days' => 20]);
        $employee = $this->employee(['hired_at' => '2025-01-15']);

        for ($month = 1; $month <= 12; $month++) {
            Carbon::setTestNow(Carbon::create(2026, $month, 1, 0, 30));
            $this->runJobs();
        }

        $this->assertSame(20.0, $this->balance($employee));
        $this->assertSame(12, LedgerEntry::query()->where('reason', 'accrual')->count());
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

    /** Dec 31 22:30 UTC is Jan 1 00:30 in Kyiv: the new year's grant and the expiry run then, not 2 hours later. */
    public function test_the_year_turns_at_kyiv_midnight_not_utc(): void
    {
        LeavePolicy::query()->update(['carry_over_max' => 5]);
        $employee = $this->employee(['hired_at' => '2025-01-15']);
        Carbon::setTestNow('2026-06-01 09:00:00');
        $this->runJobs();

        Carbon::setTestNow('2026-12-31 22:30:00'); // 2027-01-01 00:30 Kyiv
        $this->assertSame(['ok' => true, 'employees' => 1, 'accrued' => 1, 'expired' => 1], $this->runJobs());
        $this->assertSame(['2026', '2027'], LedgerEntry::query()->where('reason', 'accrual')->orderBy('id')->pluck('period')->all());
        $this->assertSame('2027', LedgerEntry::query()->where('reason', 'expiry')->sole()->period);
        $this->assertSame(29.0, $this->balance($employee));
    }

    /** Monthly grant at 00:30 Kyiv on the 1st (22:30 UTC of the last day, winter): the new month's period. */
    public function test_the_month_turns_at_kyiv_midnight(): void
    {
        LeavePolicy::query()->update(['accrual_mode' => 'monthly', 'annual_days' => 24]);
        $this->employee(['hired_at' => '2025-01-15']);

        Carbon::setTestNow('2026-01-31 22:30:00'); // 2026-02-01 00:30 Kyiv (+02:00)
        $this->runJobs();

        $this->assertSame(['2026-02'], LedgerEntry::query()->pluck('period')->all());
        $this->assertSame(4.0, (float) LedgerEntry::query()->sole()->delta); // Jan + Feb = 24 × 2/12
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
