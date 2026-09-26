<?php

declare(strict_types=1);

namespace App\Modules\People\Repositories;

use App\Modules\People\Contracts\ChangeRequestRepository;
use App\Modules\People\Enums\ChangeRequestStatus;
use App\Modules\People\Models\EmployeeChangeRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

final class EloquentChangeRequestRepository implements ChangeRequestRepository
{
    private const array RELATIONS = ['employee', 'requester', 'decider'];

    public function paginate(?array $employeeIds, ?ChangeRequestStatus $status, ?int $employeeId, int $perPage): LengthAwarePaginator
    {
        return EmployeeChangeRequest::query()->with(self::RELATIONS)
            ->when($employeeIds !== null, fn (Builder $q) => $q->whereIn('employee_id', $employeeIds ?? []))
            ->when($status, fn (Builder $q, ChangeRequestStatus $s) => $q->where('status', $s->value))
            ->when($employeeId, fn (Builder $q, int $id) => $q->where('employee_id', $id))
            ->orderByRaw("case status when 'pending' then 0 else 1 end")
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function find(int $id): ?EmployeeChangeRequest
    {
        return EmployeeChangeRequest::query()->with(self::RELATIONS)->find($id);
    }

    public function create(array $attributes): EmployeeChangeRequest
    {
        return EmployeeChangeRequest::query()->create($attributes);
    }

    public function decideIfPending(EmployeeChangeRequest $request, array $attributes): bool
    {
        return EmployeeChangeRequest::query()
            ->whereKey($request->id)
            ->where('status', ChangeRequestStatus::Pending->value)
            ->update($attributes) === 1;
    }
}
