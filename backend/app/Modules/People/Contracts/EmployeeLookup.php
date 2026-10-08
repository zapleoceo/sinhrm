<?php

declare(strict_types=1);

namespace App\Modules\People\Contracts;

use App\Modules\People\DTO\EmployeeFilter;
use App\Modules\People\DTO\PeopleContext;
use App\Modules\People\Models\Employee;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** Employee lookups other modules need (implemented by People\Services\EmployeeService). */
interface EmployeeLookup
{
    /** @throws ModelNotFoundException<Employee> */
    public function find(int $id): Employee;

    /**
     * The directory query of the person picker; terminated people only for admins and managers above them.
     *
     * @return LengthAwarePaginator<int, Employee>
     */
    public function list(PeopleContext $ctx, EmployeeFilter $filter): LengthAwarePaginator;
}
