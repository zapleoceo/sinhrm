<?php

declare(strict_types=1);

namespace App\Modules\People\Contracts;

use App\Modules\People\Enums\ChangeRequestStatus;
use App\Modules\People\Models\EmployeeChangeRequest;
use Illuminate\Pagination\LengthAwarePaginator;

interface ChangeRequestRepository
{
    /**
     * @param  list<int>|null  $employeeIds  null = every employee
     * @return LengthAwarePaginator<int, EmployeeChangeRequest>
     */
    public function paginate(?array $employeeIds, ?ChangeRequestStatus $status, ?int $employeeId, int $perPage): LengthAwarePaginator;

    public function find(int $id): ?EmployeeChangeRequest;

    /** @param  array<string, mixed>  $attributes */
    public function create(array $attributes): EmployeeChangeRequest;

    /**
     * Sets the decision only if the request is still pending (compare-and-set): false when someone decided first.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function decideIfPending(EmployeeChangeRequest $request, array $attributes): bool;
}
