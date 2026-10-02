<?php

declare(strict_types=1);

namespace App\Modules\People\Repositories;

use App\Modules\Core\Support\Database\Like;
use App\Modules\People\Contracts\EmployeeRepository;
use App\Modules\People\DTO\EmployeeFilter;
use App\Modules\People\Enums\EmployeeSort;
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
        $query = Employee::query()->with(self::RELATIONS)
            ->when(
                $filter->status,
                fn (Builder $q, EmployeeStatus $s) => $q->where('status', $s->value),
                fn (Builder $q) => match (true) {
                    ! $filter->anyStatus => $q->where('status', '!=', EmployeeStatus::Terminated->value),
                    $filter->terminatedWithin === null => $q,
                    default => $q->where(fn (Builder $w) => $w->where('status', '!=', EmployeeStatus::Terminated->value)
                        ->orWhereIn('id', $filter->terminatedWithin ?? [])),
                },
            )
            // !== null, not truthy: a search or filter for "0" is a real one (when() skips falsy values).
            ->when($filter->q !== null, function (Builder $q) use ($filter): void {
                $like = self::like((string) $filter->q);
                $q->where(fn (Builder $w) => $w->whereRaw('lower(full_name) like ?', [$like])
                    ->orWhereRaw('lower(work_email) like ?', [$like])
                    ->orWhere('phone', 'like', $like));
            })
            // Column filters of the table headers: "contains", case-insensitive, values only as bindings.
            ->when($filter->name !== null, fn (Builder $q) => $q->whereRaw('lower(full_name) like ?', [self::like((string) $filter->name)]))
            ->when($filter->contact !== null, fn (Builder $q) => $q->where(
                fn (Builder $w) => $w->whereRaw('lower(work_email) like ?', [self::like((string) $filter->contact)])
                    ->orWhere('phone', 'like', self::like((string) $filter->contact)),
            ))
            ->when($filter->manager !== null, fn (Builder $q) => $q->whereHas(
                'manager',
                fn (Builder $m) => $m->whereRaw('lower(full_name) like ?', [self::like((string) $filter->manager)]),
            ))
            ->when($filter->onlyIds !== null, fn (Builder $q) => $q->whereIn('id', $filter->onlyIds ?? []))
            ->when($filter->branchId, fn (Builder $q, int $id) => $q->where('branch_id', $id))
            ->when($filter->departmentId, fn (Builder $q, int $id) => $q->where('department_id', $id))
            ->when($filter->positionId, fn (Builder $q, int $id) => $q->where('position_id', $id))
            ->when($filter->managerId, fn (Builder $q, int $id) => $q->where('manager_id', $id));
        self::sort($query, $filter->sort, $filter->descending);

        return $query->paginate($filter->perPage);
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

    public function lockForUpdate(int $id): void
    {
        Employee::query()->whereKey($id)->lockForUpdate()->first();
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

    /** "%term%" for LIKE: lower-cased, with % _ \ escaped (the term is a binding, never SQL). */
    private static function like(string $term): string
    {
        return Like::contains(mb_strtolower($term));
    }

    /**
     * ORDER BY of a whitelisted column. Related names come from a correlated subquery (no join, so the selected
     * columns and the pagination count stay as they are). Postgres puts NULLs first on DESC: «nulls last» keeps
     * rows without a value at the end in both directions. Ties: by name, then id — stable pages.
     *
     * @param  Builder<Employee>  $q
     */
    private static function sort(Builder $q, EmployeeSort $sort, bool $descending): void
    {
        $dir = $descending ? 'desc' : 'asc';
        $related = match ($sort) {
            EmployeeSort::Name => null,
            EmployeeSort::Position => DB::table('positions')->select('positions.name')->whereColumn('positions.id', 'employees.position_id'),
            EmployeeSort::Department => DB::table('departments')->select('departments.name')->whereColumn('departments.id', 'employees.department_id'),
            EmployeeSort::Branch => DB::table('branches')->select('branches.name')->whereColumn('branches.id', 'employees.branch_id'),
            EmployeeSort::Manager => DB::table('employees as mgr')->select('mgr.full_name')->whereColumn('mgr.id', 'employees.manager_id'),
        };
        if ($related === null) {
            $q->orderBy('employees.full_name', $dir)->orderBy('employees.id', $dir);

            return;
        }
        // $dir is one of two literals above, never request text.
        $q->orderByRaw('('.$related->toSql().') '.$dir.' nulls last', $related->getBindings())
            ->orderBy('employees.full_name')
            ->orderBy('employees.id');
    }
}
