<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Models\User;
use App\Modules\Audit\Contracts\AuditContext;
use App\Modules\People\Contracts\EmployeeRepository;
use App\Modules\People\Exceptions\PeopleException;
use App\Modules\People\Models\Employee;

/**
 * Bulk actions on the employees list (HR staff, route gate people-manage). Each item goes through
 * EmployeeService::update — the same rules (manager cycle, audit observer) as a single edit.
 * Audit: one row per changed employee, marked meta.bulk = "people.update".
 */
final readonly class EmployeeBulkService
{
    public function __construct(
        private EmployeeRepository $employees,
        private EmployeeService $service,
        private AuditContext $audit,
    ) {}

    /**
     * @param  list<int>  $ids
     * @param  array<string, int>  $change
     * @return list<array{id: int, ok: bool, error: string|null}>
     */
    public function update(User $actor, array $ids, array $change): array
    {
        $results = [];
        foreach ($ids as $id) {
            $error = null;
            $employee = $this->employees->find($id);
            if ($employee === null) {
                $error = 'not_found';
            } elseif ($employee->isTerminated()) {
                $error = 'terminated';
            } else {
                try {
                    $this->audit->within(['bulk' => 'people.update'], fn (): mixed => $this->service->update($actor, $employee, $change));
                } catch (PeopleException $e) {
                    $error = $e->errorCode;
                }
            }
            $results[] = ['id' => $id, 'ok' => $error === null, 'error' => $error];
        }

        return $results;
    }

    /**
     * Directory + job tier only (no PII, no salary) for the CSV export.
     *
     * @param  list<int>  $ids
     * @return list<list<string|int|null>>
     */
    public function exportRows(array $ids): array
    {
        $rows = [];
        foreach ($ids as $id) {
            $e = $this->employees->find($id);
            if ($e instanceof Employee) {
                $rows[] = [
                    $e->id, $e->full_name, $e->work_email, $e->position?->name, $e->department?->name, $e->branch?->name,
                    $e->manager?->full_name, $e->hired_at->toDateString(), $e->status->value,
                ];
            }
        }

        return $rows;
    }
}
