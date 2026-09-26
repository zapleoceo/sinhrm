<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Services;

use App\Modules\People\DTO\PeopleContext;
use App\Modules\People\Exceptions\PeopleException;
use App\Modules\People\Models\Employee;
use App\Modules\People\Services\EmployeeService;
use App\Modules\TimeOff\Exceptions\TimeOffException;

/**
 * Whose leave a request is about: ?employee_id when the caller may see that employee's job data (admin, self,
 * manager above), otherwise 403; no id → the caller's own employee (404 no_employee without one).
 */
final readonly class EmployeeResolver
{
    public function __construct(private EmployeeService $employees) {}

    /** @throws TimeOffException|PeopleException */
    public function resolve(PeopleContext $ctx, ?int $employeeId): Employee
    {
        if ($employeeId === null) {
            if ($ctx->selfId === null) {
                throw PeopleException::noEmployee();
            }

            return $this->employees->find($ctx->selfId);
        }
        if (! $ctx->canSeeJob($employeeId)) {
            throw TimeOffException::forbidden();
        }

        return $this->employees->find($employeeId);
    }
}
