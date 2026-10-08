<?php

declare(strict_types=1);

namespace Tests\Unit\Reports;

use App\Models\User;
use App\Modules\Auth\Enums\UserStatus;
use App\Modules\People\DTO\PeopleContext;
use App\Modules\Recruiting\DTO\Scope;
use App\Modules\Reports\Contracts\ReportDataRepository;
use App\Modules\Reports\Contracts\ReportDefinition;
use App\Modules\Reports\Definitions\AbsencesSummaryReport;
use App\Modules\Reports\Definitions\AbstractRecruitingReport;
use App\Modules\Reports\Definitions\AgeReport;
use App\Modules\Reports\Definitions\LeaveUsageReport;
use App\Modules\Reports\Definitions\TenureReport;
use App\Modules\Reports\DTO\ScopedContext;
use App\Modules\Reports\Enums\ReportGroup;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/** The shared report frames (Recruiting, team, leave, bucket) keep the exact catalog contract and row shapes. */
final class ReportBaseClassesTest extends TestCase
{
    private const array EMPLOYEES = [
        ['id' => 1, 'branch_id' => null, 'branch' => null, 'department' => null, 'hired_at' => '2026-01-10', 'fired_at' => null, 'birth_date' => '2000-05-01'],
        ['id' => 2, 'branch_id' => null, 'branch' => null, 'department' => null, 'hired_at' => '2022-03-01', 'fired_at' => null, 'birth_date' => '1980-01-01'],
        ['id' => 3, 'branch_id' => null, 'branch' => null, 'department' => null, 'hired_at' => '2015-03-01', 'fired_at' => null, 'birth_date' => null],
        ['id' => 4, 'branch_id' => null, 'branch' => null, 'department' => null, 'hired_at' => '2015-03-01', 'fired_at' => '2020-01-01', 'birth_date' => '1990-01-01'],
    ];

    private const array LEAVE = [
        ['employee_id' => 1, 'leave_type' => 'vacation', 'starts_on' => '2026-08-03', 'ends_on' => '2026-08-05', 'days' => 3.0],
        ['employee_id' => 1, 'leave_type' => 'vacation', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-01', 'days' => 0.333],
        ['employee_id' => 2, 'leave_type' => 'sick', 'starts_on' => '2026-09-10', 'ends_on' => '2026-09-11', 'days' => 2.0],
        ['employee_id' => 2, 'leave_type' => 'vacation', 'starts_on' => '2026-09-14', 'ends_on' => '2026-09-14', 'days' => 1.0],
    ];

    public function test_recruiting_frame_fixes_group_period_filters_and_active_user_availability(): void
    {
        $report = new class extends AbstractRecruitingReport
        {
            public function key(): string
            {
                return 'probe';
            }

            public function columns(): array
            {
                return [];
            }

            public function rows(ScopedContext $ctx, array $filters): array
            {
                return [];
            }
        };

        self::assertSame(ReportGroup::Recruiting, $report->group());
        self::assertSame([ReportDefinition::FILTER_FROM, ReportDefinition::FILTER_TO], $report->filters());
        self::assertTrue($report->available($this->ctx(admin: false, active: true)));
        self::assertFalse($report->available($this->ctx(admin: true, active: false)));
    }

    public function test_bucket_reports_count_working_employees_per_bucket(): void
    {
        $repo = $this->repo();
        $tenure = new TenureReport($repo);
        $age = new AgeReport($repo);
        $ctx = $this->ctx(admin: true);

        self::assertSame(ReportGroup::Hr, $tenure->group());
        self::assertSame([ReportDefinition::FILTER_BRANCH], $age->filters());
        self::assertSame([['key' => 'bucket', 'type' => 'string'], ['key' => 'employees', 'type' => 'number', 'total' => 'sum']], $tenure->columns());
        self::assertSame(['label' => 'bucket', 'value' => 'employees'], $age->chart());
        self::assertSame([
            ['bucket' => '<1', 'employees' => 1],
            ['bucket' => '1-3', 'employees' => 0],
            ['bucket' => '3-5', 'employees' => 1],
            ['bucket' => '5+', 'employees' => 1],
        ], $tenure->rows($ctx, []));
        self::assertSame([
            ['bucket' => '<25', 'employees' => 0],
            ['bucket' => '25-34', 'employees' => 1],
            ['bucket' => '35-44', 'employees' => 0],
            ['bucket' => '45-54', 'employees' => 1],
            ['bucket' => '55+', 'employees' => 0],
            ['bucket' => 'unknown', 'employees' => 1],
        ], $age->rows($ctx, []));
        self::assertFalse($age->available($this->ctx(admin: false, subtree: [5])));
        self::assertTrue($tenure->available($this->ctx(admin: false, subtree: [5])));
    }

    public function test_leave_reports_count_distinct_people_and_round_days(): void
    {
        $repo = $this->repo();
        $ctx = $this->ctx(admin: true);
        $filters = ['from' => '2026-08-01', 'to' => '2026-09-30'];

        self::assertSame([ReportDefinition::FILTER_FROM, ReportDefinition::FILTER_TO], (new LeaveUsageReport($repo))->filters());
        self::assertSame([
            ['leave_type' => 'sick', 'requests' => 1, 'days' => 2.0, 'employees' => 1],
            ['leave_type' => 'vacation', 'requests' => 3, 'days' => 4.33, 'employees' => 2],
        ], (new LeaveUsageReport($repo))->rows($ctx, $filters));
        self::assertSame([
            ['month' => '2026-08', 'employees_absent' => 1, 'days' => 3.0],
            ['month' => '2026-09', 'employees_absent' => 2, 'days' => 3.33],
        ], (new AbsencesSummaryReport($repo))->rows($ctx, $filters));
        self::assertFalse((new LeaveUsageReport($repo))->available($this->ctx(admin: false)));
    }

    private function repo(): ReportDataRepository
    {
        $repo = $this->createStub(ReportDataRepository::class);
        $repo->method('employees')->willReturn(self::EMPLOYEES);
        $repo->method('approvedLeave')->willReturn(self::LEAVE);

        return $repo;
    }

    /** @param  list<int>  $subtree */
    private function ctx(bool $admin, bool $active = true, array $subtree = []): ScopedContext
    {
        $user = new User;
        $user->status = $active ? UserStatus::Active : UserStatus::Blocked;

        return new ScopedContext($user, new PeopleContext(1, $admin, null, $subtree), new Scope(1, null), Carbon::parse('2026-10-01'));
    }
}
