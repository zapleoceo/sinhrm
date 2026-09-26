<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Contracts;

use App\Modules\TimeOff\DTO\LeaveRequestFilter;
use App\Modules\TimeOff\Enums\LeaveRequestStatus;
use App\Modules\TimeOff\Models\LeaveRequest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

interface LeaveRequestRepository
{
    /**
     * @param  list<int>|null  $employeeIds  null = every employee
     * @return LengthAwarePaginator<int, LeaveRequest>
     */
    public function paginate(?array $employeeIds, LeaveRequestFilter $filter): LengthAwarePaginator;

    public function find(int $id): ?LeaveRequest;

    /** Pending or approved requests of the employee that intersect the dates (the one being edited excluded). */
    public function overlapping(int $employeeId, Carbon $from, Carbon $to, ?int $exceptId = null): bool;

    /** Sum of pending days of the type (reserved against the balance). */
    public function pendingDays(int $employeeId, int $leaveTypeId, ?int $exceptId = null): float;

    /** Approved days of the type that start in the year. */
    public function usedDays(int $employeeId, int $leaveTypeId, int $year): float;

    /**
     * Requests with the statuses intersecting [from, to].
     *
     * @param  list<int>|null  $employeeIds  null = every employee
     * @param  list<LeaveRequestStatus>  $statuses
     * @return Collection<int, LeaveRequest>
     */
    public function inRange(?array $employeeIds, Carbon $from, Carbon $to, array $statuses, ?int $branchId = null): Collection;

    /**
     * Pending requests of these employees, oldest first.
     *
     * @param  list<int>|null  $employeeIds  null = every employee
     * @return Collection<int, LeaveRequest>
     */
    public function pendingFor(?array $employeeIds, ?int $exceptEmployeeId, int $limit): Collection;

    /** @param  array<string, mixed>  $attributes */
    public function create(array $attributes): LeaveRequest;

    /**
     * Compare-and-set of the status: false when the request is no longer in $from (someone decided first).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function transition(LeaveRequest $request, LeaveRequestStatus $from, array $attributes): bool;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function transaction(callable $callback): mixed;
}
