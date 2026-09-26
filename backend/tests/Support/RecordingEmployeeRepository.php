<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\People\Contracts\EmployeeRepository;
use App\Modules\People\DTO\EmployeeFilter;
use App\Modules\People\Models\Employee;
use App\Modules\People\Repositories\EloquentEmployeeRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Real repository that records lockForUpdate() calls and whether each ran inside a transaction.
 * SQLite compiles FOR UPDATE to nothing, so the lock path is asserted through the contract instead.
 */
final class RecordingEmployeeRepository implements EmployeeRepository
{
    /** @var list<array{id: int, in_transaction: bool}> */
    public array $locks = [];

    public function __construct(private readonly EloquentEmployeeRepository $inner) {}

    public function lockForUpdate(int $id): void
    {
        $this->locks[] = ['id' => $id, 'in_transaction' => DB::transactionLevel() > 0];
        $this->inner->lockForUpdate($id);
    }

    public function paginate(EmployeeFilter $filter): LengthAwarePaginator
    {
        return $this->inner->paginate($filter);
    }

    public function find(int $id): ?Employee
    {
        return $this->inner->find($id);
    }

    public function findByUser(int $userId): ?Employee
    {
        return $this->inner->findByUser($userId);
    }

    public function findByApplication(int $applicationId): ?Employee
    {
        return $this->inner->findByApplication($applicationId);
    }

    public function findByCandidate(int $candidateId): ?Employee
    {
        return $this->inner->findByCandidate($candidateId);
    }

    public function managerMap(): array
    {
        return $this->inner->managerMap();
    }

    public function forChart(?int $branchId): Collection
    {
        return $this->inner->forChart($branchId);
    }

    public function working(?array $ids = null, ?int $branchId = null): Collection
    {
        return $this->inner->working($ids, $branchId);
    }

    public function create(array $attributes): Employee
    {
        return $this->inner->create($attributes);
    }

    public function update(Employee $employee, array $attributes): Employee
    {
        return $this->inner->update($employee, $attributes);
    }

    public function transaction(callable $callback): mixed
    {
        return $this->inner->transaction($callback);
    }
}
