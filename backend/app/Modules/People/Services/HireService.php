<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Models\User;
use App\Modules\People\Contracts\EmployeeRepository;
use App\Modules\People\DTO\HireResult;
use App\Modules\People\Enums\EmployeeStatus;
use App\Modules\People\Enums\EmploymentType;
use App\Modules\People\Events\EmployeeHired;
use App\Modules\People\Exceptions\PeopleException;
use App\Modules\People\Models\Employee;
use App\Modules\Recruiting\Enums\ApplicationStatus;
use App\Modules\Recruiting\Models\Application;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * "Hire → create employee" from a hired application (Recruiting): name and contacts from the candidate, branch,
 * department and position from the vacancy. Idempotent: the application (and the candidate) map to one employee.
 */
final readonly class HireService
{
    public function __construct(
        private EmployeeRepository $employees,
        private Dispatcher $events,
        private LoggerInterface $log,
    ) {}

    /** @throws PeopleException not_hired */
    public function hire(User $actor, Application $application, Carbon $hiredAt): HireResult
    {
        $existing = $this->existing($application);
        if ($existing !== null) {
            return new HireResult($existing, false);
        }
        if ($application->status !== ApplicationStatus::Hired) {
            throw PeopleException::notHired();
        }
        $candidate = $application->candidate;
        $vacancy = $application->vacancy;
        try {
            $employee = $this->employees->create([
                'full_name' => $candidate->full_name,
                // The candidate's e-mail is personal; the work e-mail is set by an admin later.
                'personal_email' => $candidate->email,
                'phone' => $candidate->phone,
                'hired_at' => $hiredAt->toDateString(),
                'status' => EmployeeStatus::Active->value,
                'employment_type' => EmploymentType::FullTime->value,
                'branch_id' => $vacancy->branch_id,
                'department_id' => $vacancy->department_id,
                'position_id' => $vacancy->position_id,
                'candidate_id' => $candidate->id,
                'application_id' => $application->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent click created it first.
            $existing = $this->existing($application);
            assert($existing !== null);

            return new HireResult($existing, false);
        }
        $this->log->info('people.employee_hired', ['id' => $employee->id, 'application' => $application->id, 'by' => $actor->id]);
        $this->events->dispatch(new EmployeeHired($employee));

        return new HireResult($employee, true);
    }

    private function existing(Application $application): ?Employee
    {
        return $this->employees->findByApplication($application->id)
            ?? $this->employees->findByCandidate($application->candidate_id);
    }
}
