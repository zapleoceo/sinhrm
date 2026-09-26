<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Models\User;
use App\Modules\People\Contracts\EmployeeRepository;
use App\Modules\People\DTO\EmployeeFilter;
use App\Modules\People\DTO\PeopleContext;
use App\Modules\People\Enums\EmployeeStatus;
use App\Modules\People\Events\EmployeeHired;
use App\Modules\People\Events\EmployeeTerminated;
use App\Modules\People\Exceptions\PeopleException;
use App\Modules\People\Models\Employee;
use App\Modules\People\Support\ReportingTree;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/** Employee records: directory, profile, create/edit/terminate (admin), org chart. */
final readonly class EmployeeService
{
    public function __construct(
        private EmployeeRepository $employees,
        private Dispatcher $events,
        private LoggerInterface $log,
    ) {}

    /**
     * Terminated people are listed only to admins and to managers who were above them (their subtree).
     *
     * @return LengthAwarePaginator<int, Employee>
     */
    public function list(PeopleContext $ctx, EmployeeFilter $filter): LengthAwarePaginator
    {
        if ($filter->status === EmployeeStatus::Terminated && ! $ctx->admin) {
            $filter = $filter->restrictedTo($ctx->subtreeIds);
        }

        return $this->employees->paginate($filter);
    }

    /**
     * Profile lookup: a terminated employee is 404 for everyone but admins and managers above them.
     *
     * @throws ModelNotFoundException<Employee>
     */
    public function findVisible(PeopleContext $ctx, int $id): Employee
    {
        $employee = $this->find($id);
        if ($employee->isTerminated() && ! $ctx->admin && ! $ctx->isAbove($id)) {
            throw (new ModelNotFoundException)->setModel(Employee::class, [$id]);
        }

        return $employee;
    }

    /** @throws ModelNotFoundException<Employee> */
    public function find(int $id): Employee
    {
        return $this->employees->find($id) ?? throw (new ModelNotFoundException)->setModel(Employee::class, [$id]);
    }

    /** @param  array<string, mixed>  $attributes */
    public function create(User $actor, array $attributes): Employee
    {
        $attributes['status'] ??= EmployeeStatus::Active->value;
        $employee = $this->employees->transaction(function () use ($attributes): Employee {
            $employee = $this->employees->create($attributes);
            // A new record has no reports yet, but keep the invariant in one place (e.g. future subtree transplant).
            if (isset($attributes['manager_id'])) {
                $this->assertNoCycle($employee->id, (int) $attributes['manager_id']);
            }

            return $employee;
        });
        $this->log->info('people.employee_created', ['id' => $employee->id, 'by' => $actor->id]);
        $this->events->dispatch(new EmployeeHired($employee));

        return $this->find($employee->id);
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws PeopleException manager_cycle
     */
    public function update(User $actor, Employee $employee, array $attributes): Employee
    {
        if ($attributes === []) {
            return $this->find($employee->id);
        }
        if (isset($attributes['manager_id'])) {
            $this->assertNoCycle($employee->id, (int) $attributes['manager_id']);
        }
        $this->employees->update($employee, $attributes);
        $this->log->info('people.employee_updated', ['id' => $employee->id, 'by' => $actor->id, 'fields' => array_keys($attributes)]);

        return $this->find($employee->id);
    }

    /** @throws PeopleException already_terminated */
    public function terminate(User $actor, Employee $employee, Carbon $firedAt, ?string $reason): Employee
    {
        if ($employee->isTerminated()) {
            throw PeopleException::alreadyTerminated();
        }
        $this->employees->update($employee, [
            'status' => EmployeeStatus::Terminated->value,
            'fired_at' => $firedAt->toDateString(),
            'termination_reason' => $reason,
        ]);
        $this->log->info('people.employee_terminated', ['id' => $employee->id, 'by' => $actor->id]);
        $terminated = $this->find($employee->id);
        $this->events->dispatch(new EmployeeTerminated($terminated));

        return $terminated;
    }

    /**
     * Org chart as a forest of not terminated employees. $rootId limits it to that employee and everyone below.
     * Nodes carry the directory tier only (visible to every active user anyway).
     *
     * @return list<array<string, mixed>>
     */
    public function orgChart(?int $branchId, ?int $rootId): array
    {
        $people = $this->employees->forChart($branchId)->keyBy('id');
        $managerOf = [];
        foreach ($people as $id => $employee) {
            // A manager outside the filtered set (terminated / other branch) makes the employee a root.
            $managerOf[(int) $id] = $employee->manager_id !== null && $people->has($employee->manager_id) ? $employee->manager_id : null;
        }
        $children = ReportingTree::children($managerOf);
        if ($rootId !== null) {
            $roots = $people->has($rootId) ? [$rootId] : [];
        } else {
            $roots = array_keys(array_filter($managerOf, static fn (?int $m): bool => $m === null));
        }

        $build = function (int $id, array $path) use (&$build, $people, $children): array {
            $employee = $people->get($id);
            assert($employee instanceof Employee);
            $path[$id] = true;
            $reports = [];
            foreach ($children[$id] ?? [] as $child) {
                if (! isset($path[$child])) {
                    $reports[] = $build($child, $path);
                }
            }

            return [
                'id' => $employee->id,
                'full_name' => $employee->full_name,
                'avatar_url' => $employee->avatar_url,
                'position' => $employee->position === null ? null : ['id' => $employee->position->id, 'name' => $employee->position->name],
                'department' => $employee->department === null ? null : ['id' => $employee->department->id, 'name' => $employee->department->name],
                'branch' => $employee->branch === null ? null : ['id' => $employee->branch->id, 'name' => $employee->branch->name],
                'reports_count' => count($reports),
                'reports' => $reports,
            ];
        };

        return array_map(static fn (int $id): array => $build($id, []), $roots);
    }

    private function assertNoCycle(int $employeeId, int $managerId): void
    {
        if ($managerId === $employeeId
            || in_array($managerId, ReportingTree::descendants($this->employees->managerMap(), $employeeId), true)) {
            throw PeopleException::managerCycle();
        }
    }
}
