<?php

declare(strict_types=1);

namespace App\Modules\People\Contracts;

use App\Modules\People\DTO\EmployeeFilter;
use App\Modules\People\Models\Employee;
use App\Modules\People\Models\EmployeeCompensation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

interface EmployeeRepository
{
    /** @return LengthAwarePaginator<int, Employee> */
    public function paginate(EmployeeFilter $filter): LengthAwarePaginator;

    public function find(int $id): ?Employee;

    public function findByUser(int $userId): ?Employee;

    public function findByApplication(int $applicationId): ?Employee;

    public function findByCandidate(int $candidateId): ?Employee;

    /** @return array<int, int|null> employee id → manager id, every employee (terminated included) */
    public function managerMap(): array;

    /**
     * Not terminated employees for the org chart, with position/branch/department.
     *
     * @return Collection<int, Employee>
     */
    public function forChart(?int $branchId): Collection;

    /**
     * Not terminated employees (for accrual and calendars).
     *
     * @param  list<int>|null  $ids  null = all
     * @return Collection<int, Employee>
     */
    public function working(?array $ids = null, ?int $branchId = null): Collection;

    /**
     * SELECT … FOR UPDATE on the employee row (inside a transaction): serializes leave requests, approvals and
     * ledger writes of one employee (overlap and balance checks are read-then-write).
     */
    public function lockForUpdate(int $id): void;

    /**
     * Scheduled terminations now in force: not terminated, fired_at < $today (a date). Access ends at the end of day fired_at.
     *
     * @return list<int>
     */
    public function dueTerminations(Carbon $today): array;

    /**
     * Terminated employees whose EmployeeTerminated did not go through yet (termination_event_pending).
     *
     * @return list<int>
     */
    public function pendingTerminationEvents(): array;

    /** Failed deliveries in a row after +1 (atomic, no model events: a technical counter, not an audited change). */
    public function bumpTerminationEventAttempts(int $id): int;

    /** Clears termination_event_pending and the attempts counter without model events (technical, not audited). */
    public function markTerminationEventSent(int $id): void;

    /** @param  array<string, mixed>  $attributes */
    public function create(array $attributes): Employee;

    /** @param  array<string, mixed>  $attributes  employee_id, created_by and the validated fields */
    public function addCompensation(array $attributes): EmployeeCompensation;

    /**
     * Compensation records of the employee, newest effective_on (then id) first.
     *
     * @return Collection<int, EmployeeCompensation>
     */
    public function compensationHistory(int $employeeId): Collection;

    /** @param  array<string, mixed>  $attributes */
    public function update(Employee $employee, array $attributes): Employee;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function transaction(callable $callback): mixed;
}
