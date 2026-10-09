<?php

declare(strict_types=1);

namespace Tests\Unit\HiringRequests;

use App\Modules\HiringRequests\Contracts\HiringRequestRepository;
use App\Modules\HiringRequests\Exceptions\HiringException;
use App\Modules\HiringRequests\Models\HiringRequest;
use App\Modules\HiringRequests\Models\HiringRouteStep;
use App\Modules\HiringRequests\Services\HiringProgress;
use App\Modules\HiringRequests\Services\RouteSnapshot;
use App\Modules\HiringRequests\Support\RequestAttributes;
use App\Modules\HiringRequests\Support\VacancyDraft;
use App\Modules\People\Contracts\EmployeeRepository;
use App\Modules\People\Models\Employee;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

/** Parts extracted from HiringRequestService: route snapshot, form attributes, vacancy draft, hiring progress. */
final class HiringRequestPartsTest extends TestCase
{
    public function test_route_snapshot_resolves_manager_user_and_role_and_skips_the_rest(): void
    {
        $requests = $this->createStub(HiringRequestRepository::class);
        $requests->method('routeSteps')->willReturn(new Collection([
            (new HiringRouteStep)->forceFill(['position' => 1, 'name' => 'Manager', 'kind' => 'manager', 'sla_days' => 2]),
            (new HiringRouteStep)->forceFill(['position' => 2, 'name' => 'Self', 'kind' => 'user', 'user_id' => 10]),
            (new HiringRouteStep)->forceFill(['position' => 3, 'name' => 'HR', 'kind' => 'role', 'role' => 'admin']),
            (new HiringRouteStep)->forceFill(['position' => 4, 'name' => 'Blocked', 'kind' => 'user', 'user_id' => 99]),
        ]));
        $requests->method('isActiveUser')->willReturnCallback(static fn (int $id): bool => $id !== 99);
        $employees = $this->createStub(EmployeeRepository::class);
        $employees->method('findByUser')->willReturn((new Employee)->forceFill(['id' => 1, 'manager_id' => 2]));
        $employees->method('find')->willReturn((new Employee)->forceFill(['id' => 2, 'user_id' => 20]));

        $steps = (new RouteSnapshot($requests, $employees))->of((new HiringRequest)->forceFill(['requester_id' => 10]));

        $this->assertSame([
            ['position' => 1, 'name' => 'Manager', 'kind' => 'manager', 'role' => null, 'approver_id' => 20, 'sla_days' => 2, 'status' => 'waiting'],
            ['position' => 2, 'name' => 'Self', 'kind' => 'user', 'role' => null, 'approver_id' => null, 'sla_days' => null, 'status' => 'skipped'],
            ['position' => 3, 'name' => 'HR', 'kind' => 'role', 'role' => 'admin', 'approver_id' => null, 'sla_days' => null, 'status' => 'waiting'],
            ['position' => 4, 'name' => 'Blocked', 'kind' => 'user', 'role' => null, 'approver_id' => null, 'sla_days' => null, 'status' => 'skipped'],
        ], $steps);
    }

    public function test_attributes_keep_sent_keys_clear_replacement_and_check_salary(): void
    {
        $noFields = static fn (): array => [];

        $this->assertSame(['title' => 'Dev', 'reason' => 'new_position', 'replaced_employee_id' => null, 'extra' => []],
            RequestAttributes::from(['title' => 'Dev', 'reason' => 'new_position', 'replaced_employee_id' => 5, 'unknown' => 1], true, null, $noFields));
        $this->assertSame(['salary_max' => 900],
            RequestAttributes::from(['salary_max' => 900, 'salary_min' => 100], false, ['salary_max' => 900], static fn (): array => throw new \LogicException('form not read')));

        $this->expectExceptionObject(HiringException::salaryRange());
        RequestAttributes::from(['salary_min' => 1000, 'salary_max' => 900], false, null, $noFields);
    }

    public function test_vacancy_draft_and_progress(): void
    {
        $request = (new HiringRequest)->forceFill([
            'id' => 7, 'title' => 'Dev', 'branch_id' => 1, 'department_id' => 2, 'position_id' => 3, 'requirements' => 'PHP',
            'headcount' => 4, 'desired_start_date' => '2026-11-02', 'salary_min' => '100', 'salary_max' => null, 'currency' => 'UAH', 'vacancy_id' => 50,
        ])->setRelation('vacancy', null);

        $this->assertSame([
            'title' => 'Dev', 'branch_id' => 1, 'department_id' => 2, 'position_id' => 3,
            'description' => "PHP\n\nКількість позицій: 4\n\nБажана дата виходу: 02.11.2026\n\nЗарплата: 100 –  UAH\n\nЗаявка на підбір #7",
            'status' => 'open',
        ], VacancyDraft::from($request));

        $requests = $this->createStub(HiringRequestRepository::class);
        $requests->method('hiresByVacancy')->willReturn([50 => 3]);
        $this->assertSame([7 => ['vacancy_status' => null, 'hired' => 3, 'headcount' => 4, 'percent' => 75]], (new HiringProgress($requests))->of([$request]));
    }
}
