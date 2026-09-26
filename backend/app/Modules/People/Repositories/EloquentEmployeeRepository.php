<?php

declare(strict_types=1);

namespace App\Modules\People\Repositories;

use App\Modules\People\Contracts\EmployeeRepository;
use App\Modules\People\DTO\EmployeeFilter;
use App\Modules\People\Enums\EmployeeStatus;
use App\Modules\People\Models\Employee;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class EloquentEmployeeRepository implements EmployeeRepository
{
    private const array RELATIONS = ['branch', 'department', 'position', 'manager'];

    public function paginate(EmployeeFilter $filter): LengthAwarePaginator
    {
        return Employee::query()->with(self::RELATIONS)
            ->when(
                $filter->status,
                fn (Builder $q, EmployeeStatus $s) => $q->where('status', $s->value),
                fn (Builder $q) => $q->where('status', '!=', EmployeeStatus::Terminated->value),
            )
            ->when($filter->q, function (Builder $q, string $term): void {
                $like = '%'.addcslashes(mb_strtolower($term), '%_\\').'%';
                $q->where(fn (Builder $w) => $w->whereRaw('lower(full_name) like ?', [$like])
                    ->orWhereRaw('lower(work_email) like ?', [$like])
                    ->orWhere('phone', 'like', $like));
            })
            ->when($filter->branchId, fn (Builder $q, int $id) => $q->where('branch_id', $id))
            ->when($filter->departmentId, fn (Builder $q, int $id) => $q->where('department_id', $id))
            ->when($filter->positionId, fn (Builder $q, int $id) => $q->where('position_id', $id))
            ->when($filter->managerId, fn (Builder $q, int $id) => $q->where('manager_id', $id))
            ->orderBy('full_name')
            ->orderBy('id')
            ->paginate($filter->perPage);
    }

    public function find(int $id): ?Employee
    {
        return Employee::query()->with([...self::RELATIONS, 'user'])->withCount('reports')->find($id);
    }

    public function findByUser(int $userId): ?Employee
    {
        return Employee::query()->with([...self::RELATIONS, 'user'])->withCount('reports')->where('user_id', $userId)->first();
    }

    public function findByApplication(int $applicationId): ?Employee
    {
        return Employee::query()->where('application_id', $applicationId)->first();
    }

    public function findByCandidate(int $candidateId): ?Employee
    {
        return Employee::query()->where('candidate_id', $candidateId)->first();
    }

    public function managerMap(): array
    {
        $map = [];
        foreach (Employee::query()->toBase()->get(['id', 'manager_id']) as $row) {
            $map[(int) $row->id] = $row->manager_id === null ? null : (int) $row->manager_id;
        }

        return $map;
    }

    public function forChart(?int $branchId): Collection
    {
        return Employee::query()->with(['position', 'branch', 'department'])
            ->where('status', '!=', EmployeeStatus::Terminated->value)
            ->when($branchId, fn (Builder $q, int $id) => $q->where('branch_id', $id))
            ->orderBy('full_name')
            ->get();
    }

    public function working(?array $ids = null, ?int $branchId = null): Collection
    {
        return Employee::query()
            ->where('status', '!=', EmployeeStatus::Terminated->value)
            ->when($ids !== null, fn (Builder $q) => $q->whereIn('id', $ids ?? []))
            ->when($branchId, fn (Builder $q, int $id) => $q->where('branch_id', $id))
            ->orderBy('full_name')
            ->get();
    }

    public function create(array $attributes): Employee
    {
        return Employee::query()->create($attributes);
    }

    public function update(Employee $employee, array $attributes): Employee
    {
        $employee->fill($attributes)->save();

        return $employee;
    }

    public function transaction(callable $callback): mixed
    {
        return DB::transaction(fn (): mixed => $callback());
    }
}
