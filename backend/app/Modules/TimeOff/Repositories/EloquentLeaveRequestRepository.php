<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Repositories;

use App\Modules\TimeOff\Contracts\LeaveRequestRepository;
use App\Modules\TimeOff\DTO\LeaveRequestFilter;
use App\Modules\TimeOff\Enums\LeaveRequestStatus;
use App\Modules\TimeOff\Models\LeaveRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class EloquentLeaveRequestRepository implements LeaveRequestRepository
{
    private const array RELATIONS = ['employee', 'leaveType', 'approver'];

    private const array BLOCKING = [LeaveRequestStatus::Pending->value, LeaveRequestStatus::Approved->value];

    public function paginate(?array $employeeIds, LeaveRequestFilter $filter): LengthAwarePaginator
    {
        return LeaveRequest::query()->with(self::RELATIONS)
            ->when($employeeIds !== null, fn (Builder $q) => $q->whereIn('employee_id', $employeeIds ?? []))
            ->when($filter->employeeId, fn (Builder $q, int $id) => $q->where('employee_id', $id))
            ->when($filter->status, fn (Builder $q, LeaveRequestStatus $s) => $q->where('status', $s->value))
            ->when($filter->leaveTypeId, fn (Builder $q, int $id) => $q->where('leave_type_id', $id))
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->paginate($filter->perPage);
    }

    public function find(int $id): ?LeaveRequest
    {
        return LeaveRequest::query()->with(self::RELATIONS)->find($id);
    }

    public function overlapping(int $employeeId, Carbon $from, Carbon $to, ?int $exceptId = null): bool
    {
        return LeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->whereIn('status', self::BLOCKING)
            ->whereDate('starts_on', '<=', $to->toDateString())
            ->whereDate('ends_on', '>=', $from->toDateString())
            ->when($exceptId, fn (Builder $q, int $id) => $q->whereKeyNot($id))
            ->exists();
    }

    public function pendingDays(int $employeeId, int $leaveTypeId, ?int $exceptId = null): float
    {
        return round((float) LeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
            ->where('status', LeaveRequestStatus::Pending->value)
            ->when($exceptId, fn (Builder $q, int $id) => $q->whereKeyNot($id))
            ->sum('days'), 2);
    }

    public function usedDays(int $employeeId, int $leaveTypeId, int $year): float
    {
        return round((float) LeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
            ->where('status', LeaveRequestStatus::Approved->value)
            ->whereDate('starts_on', '>=', sprintf('%04d-01-01', $year))
            ->whereDate('starts_on', '<=', sprintf('%04d-12-31', $year))
            ->sum('days'), 2);
    }

    public function inRange(?array $employeeIds, Carbon $from, Carbon $to, array $statuses, ?int $branchId = null): Collection
    {
        return LeaveRequest::query()->with(['employee', 'leaveType'])
            ->when($employeeIds !== null, fn (Builder $q) => $q->whereIn('employee_id', $employeeIds ?? []))
            ->when($branchId, fn (Builder $q, int $id) => $q->whereHas('employee', fn (Builder $e) => $e->where('branch_id', $id)))
            ->whereIn('status', array_map(static fn (LeaveRequestStatus $s): string => $s->value, $statuses))
            ->whereDate('starts_on', '<=', $to->toDateString())
            ->whereDate('ends_on', '>=', $from->toDateString())
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get();
    }

    public function pendingFor(?array $employeeIds, ?int $exceptEmployeeId, int $limit): Collection
    {
        return $this->pending($employeeIds, $exceptEmployeeId)->with(self::RELATIONS)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    public function countPendingFor(?array $employeeIds, ?int $exceptEmployeeId): int
    {
        return $this->pending($employeeIds, $exceptEmployeeId)->count();
    }

    /**
     * @param  list<int>|null  $employeeIds
     * @return Builder<LeaveRequest>
     */
    private function pending(?array $employeeIds, ?int $exceptEmployeeId): Builder
    {
        return LeaveRequest::query()
            ->where('status', LeaveRequestStatus::Pending->value)
            ->when($employeeIds !== null, fn (Builder $q) => $q->whereIn('employee_id', $employeeIds ?? []))
            ->when($exceptEmployeeId, fn (Builder $q, int $id) => $q->where('employee_id', '!=', $id));
    }

    public function create(array $attributes): LeaveRequest
    {
        return LeaveRequest::query()->create($attributes);
    }

    public function transition(LeaveRequest $request, LeaveRequestStatus $from, array $attributes): bool
    {
        return LeaveRequest::query()
            ->whereKey($request->id)
            ->where('status', $from->value)
            ->update($attributes + ['updated_at' => Carbon::now()]) === 1;
    }

    public function transaction(callable $callback): mixed
    {
        return DB::transaction(fn (): mixed => $callback());
    }
}
