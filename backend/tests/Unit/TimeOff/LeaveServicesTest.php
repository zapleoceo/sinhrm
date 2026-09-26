<?php

declare(strict_types=1);

namespace Tests\Unit\TimeOff;

use App\Models\User;
use App\Modules\People\DTO\PeopleContext;
use App\Modules\People\Models\Employee;
use App\Modules\TimeOff\DTO\LeaveRequestData;
use App\Modules\TimeOff\Enums\LeaveRequestStatus;
use App\Modules\TimeOff\Exceptions\TimeOffException;
use App\Modules\TimeOff\Models\LeavePolicy;
use App\Modules\TimeOff\Models\LeaveType;
use App\Modules\TimeOff\Models\LedgerEntry;
use App\Modules\TimeOff\Services\AccrualService;
use App\Modules\TimeOff\Services\BalanceService;
use App\Modules\TimeOff\Services\LeaveRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** LeaveRequestService, BalanceService, AccrualService against the DB (synthetic data). */
final class LeaveServicesTest extends TestCase
{
    use RefreshDatabase;

    private LeaveType $vacation;

    private Employee $employee;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 09:00:00');
        $this->vacation = LeaveType::query()->where('code', 'vacation')->firstOrFail();
        $this->actor = User::factory()->create();
        $this->employee = Employee::factory()->create(['user_id' => $this->actor->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_admin_override_allows_a_negative_balance(): void
    {
        $service = $this->app->make(LeaveRequestService::class);
        $admin = new PeopleContext($this->actor->id, true, $this->employee->id, []);
        $data = new LeaveRequestData($this->vacation->id, Carbon::parse('2026-10-12'), Carbon::parse('2026-10-13'), overrideBalance: true);

        $request = $service->create($this->actor, $admin, $this->employee, $this->vacation, $data);
        $approved = $service->approve($this->actor, $admin, $request, null);

        $this->assertSame(LeaveRequestStatus::Approved, $approved->status);
        $this->assertSame(-2.0, $this->app->make(BalanceService::class)->available($this->employee, $this->vacation));
    }

    public function test_override_is_ignored_for_non_admins(): void
    {
        $self = new PeopleContext($this->actor->id, false, $this->employee->id, []);
        $data = new LeaveRequestData($this->vacation->id, Carbon::parse('2026-10-12'), Carbon::parse('2026-10-13'), overrideBalance: true);

        $this->expectException(TimeOffException::class);
        $this->expectExceptionMessage('insufficient_balance');
        $this->app->make(LeaveRequestService::class)->create($this->actor, $self, $this->employee, $this->vacation, $data);
    }

    public function test_nobody_but_an_admin_decides_their_own_request(): void
    {
        LedgerEntry::query()->create(['employee_id' => $this->employee->id, 'leave_type_id' => $this->vacation->id, 'delta' => 5, 'reason' => 'adjustment']);
        $service = $this->app->make(LeaveRequestService::class);
        $self = new PeopleContext($this->actor->id, false, $this->employee->id, []);
        $request = $service->create($this->actor, $self, $this->employee, $this->vacation,
            new LeaveRequestData($this->vacation->id, Carbon::parse('2026-10-12'), Carbon::parse('2026-10-12')));

        $this->expectExceptionMessage('forbidden');
        $service->approve($this->actor, $self, $request, null);
    }

    public function test_hire_grant_and_the_job_do_not_double_count(): void
    {
        $accruals = $this->app->make(AccrualService::class);
        LeavePolicy::query()->update(['annual_days' => 12]);
        $hired = Employee::factory()->create(['hired_at' => '2026-04-01']);

        $this->assertSame(['accrued' => 1, 'expired' => 0], $accruals->accrueFor($hired, Carbon::now()));
        $this->assertSame(['accrued' => 0, 'expired' => 0], $accruals->accrueFor($hired, Carbon::now()));
        $accruals->run(Carbon::now());
        $this->assertSame(9.0, (float) LedgerEntry::query()->where('employee_id', $hired->id)->sum('delta'));
    }
}
