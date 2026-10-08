<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Services;

use App\Modules\People\Contracts\EmployeeLookup;
use App\Modules\People\DTO\EmployeeFilter;
use App\Modules\People\DTO\PeopleContext;
use App\Modules\People\Exceptions\PeopleException;
use App\Modules\People\Models\Employee;
use App\Modules\TimeOff\Exceptions\TimeOffException;

/**
 * Whose leave a request is about: ?employee_id when the caller may see that employee's job data (admin, self,
 * manager above), otherwise 403; no id → the caller's own employee (404 no_employee without one).
 * handover(): the optional colleague who takes over the work — same rule as the person picker (scope employees,
 * directory query of the caller, working people only), never the absent employee; otherwise 422 invalid_handover.
 */
final readonly class EmployeeResolver
{
    public function __construct(private EmployeeLookup $employees) {}

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

    /** @throws TimeOffException invalid_handover */
    public function handover(PeopleContext $ctx, Employee $absent, ?int $handoverId): ?int
    {
        if ($handoverId === null) {
            return null;
        }
        if ($handoverId === $absent->id) {
            throw TimeOffException::invalidHandover();
        }
        // The picker's directory query (EmployeeLookup::list): status null = active + on_leave, no terminated.
        $visible = $this->employees->list($ctx, new EmployeeFilter(perPage: 1, onlyIds: [$handoverId]))->total() === 1;
        if (! $visible) {
            throw TimeOffException::invalidHandover();
        }

        return $handoverId;
    }
}
