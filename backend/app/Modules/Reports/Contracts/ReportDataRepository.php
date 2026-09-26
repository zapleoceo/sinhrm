<?php

declare(strict_types=1);

namespace App\Modules\Reports\Contracts;

use App\Modules\Recruiting\DTO\Scope;
use Illuminate\Support\Carbon;

/**
 * Read-only aggregates across modules for the report catalog. Every method takes the scope explicitly:
 * $employeeIds null = all employees (admin), a list = only these.
 */
interface ReportDataRepository
{
    /**
     * @param  list<int>|null  $employeeIds
     * @return list<array{id: int, branch_id: int|null, branch: string|null, department: string|null, hired_at: string, fired_at: string|null, birth_date: string|null}>
     */
    public function employees(?array $employeeIds, ?int $branchId): array;

    /**
     * Approved leave requests overlapping [from, to].
     *
     * @param  list<int>|null  $employeeIds
     * @return list<array{employee_id: int, leave_type: string, starts_on: string, ends_on: string, days: float}>
     */
    public function approvedLeave(?array $employeeIds, Carbon $from, Carbon $to): array;

    /**
     * Current balance (sum of the ledger) per employee and leave type that tracks a balance.
     *
     * @param  list<int>|null  $employeeIds
     * @return list<array{employee: string, leave_type: string, balance: float}>
     */
    public function balances(?array $employeeIds, int $limit): array;

    /**
     * Cases opened in the range with their category SLA fields.
     *
     * @return list<array{category: string, created_at: string, first_response_at: string|null, resolved_at: string|null, open: bool, first_response_hours: int|null, resolve_hours: int|null}>
     */
    public function deskCases(Carbon $from, Carbon $to): array;

    /** @return list<array{status: string, type: string|null, assets: int, cost: float}> */
    public function assetsByStatus(): array;

    /**
     * Hired applications closed in the range, within the recruiting scope.
     *
     * @return list<array{vacancy: string, created_at: string, closed_at: string}>
     */
    public function hiredApplications(Scope $scope, Carbon $from, Carbon $to): array;

    /**
     * Objectives of a period (all periods when null). $ownerIds null = all; otherwise personal/team objectives owned by
     * these employees only (company/branch objectives are for admins).
     *
     * @param  list<int>|null  $ownerIds
     * @return list<array{scope: string, status: string, progress: int}>
     */
    public function objectives(?array $ownerIds, ?string $period): array;

    /**
     * Assignments per active/closed review cycle (subjects limited to $subjectIds unless null).
     *
     * @param  list<int>|null  $subjectIds
     * @return list<array{cycle: string, status: string, total: int, submitted: int}>
     */
    public function reviewCycles(?array $subjectIds): array;

    /**
     * Closed waves of surveys that have an eNPS question, oldest first.
     *
     * @return list<array{wave_id: int, title: string, ends_at: string, min_group: int, question_id: string}>
     */
    public function closedEnpsWaves(int $limit): array;
}
