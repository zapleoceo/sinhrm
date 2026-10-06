<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Models\User;
use App\Modules\Core\Support\UserTime;
use App\Modules\People\Contracts\EmployeeRepository;
use App\Modules\People\DTO\PeopleContext;
use App\Modules\People\Enums\EmployeeStatus;
use App\Modules\People\Events\EmployeeRestored;
use App\Modules\People\Events\EmployeeTerminated;
use App\Modules\People\Exceptions\PeopleException;
use App\Modules\People\Models\Employee;
use App\Modules\Users\Contracts\AccountBlocker;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Termination from a date, its cancellation, and restore (owner decisions PROD-12, PROD-14).
 * - fired_at = the last working day. On or before "today" in the user's zone (UserTime, Europe/Kyiv, never the
 *   UTC date) it applies at once; a later date is scheduled: the employee keeps working and access until the END of
 *   that day, ScheduledTerminationJob applies it from 00:00 Kyiv of the next day (owner decision 2026-10-07).
 * - Applying: status terminated, the linked login blocked with every credential revoked (AccountBlocker), then
 *   EmployeeTerminated (Workflows offboarding, Pulse exit survey), exactly once per termination.
 * - Restore: working again; the login is unblocked only if that termination blocked it and nobody blocked it since.
 * Every change goes through the Employee model, so the audit log records it (who, fired_at, status).
 */
final readonly class TerminationService
{
    public function __construct(
        private EmployeeRepository $employees,
        private EmployeeService $records,
        private AccountBlocker $accounts,
        private Dispatcher $events,
        private LoggerInterface $log,
    ) {}

    /** @throws PeopleException forbidden | already_terminated | termination_scheduled */
    public function terminate(PeopleContext $ctx, User $actor, Employee $employee, Carbon $firedAt, ?string $reason): Employee
    {
        $this->authorize($ctx, $employee->id);
        $applied = $this->employees->transaction(function () use ($actor, $employee, $firedAt, $reason): bool {
            $fresh = $this->locked($employee->id);
            if ($fresh->isTerminated()) {
                throw PeopleException::alreadyTerminated();
            }
            if ($fresh->isTerminationScheduled()) {
                throw PeopleException::terminationScheduled();
            }
            $this->employees->update($fresh, ['fired_at' => $firedAt->toDateString(), 'termination_reason' => $reason]);
            if ($firedAt->toDateString() > UserTime::today()->toDateString()) {
                return false;
            }
            $this->apply($fresh, $actor->id);

            return true;
        });
        $this->log->info($applied ? 'people.employee_terminated' : 'people.termination_scheduled', ['id' => $employee->id, 'by' => $actor->id]);

        return $this->afterChange($employee->id, $applied);
    }

    /** @throws PeopleException forbidden | already_terminated | termination_not_scheduled */
    public function cancel(PeopleContext $ctx, User $actor, Employee $employee): Employee
    {
        $this->authorize($ctx, $employee->id);
        $this->employees->transaction(function () use ($employee): void {
            $fresh = $this->locked($employee->id);
            if ($fresh->isTerminated()) {
                throw PeopleException::alreadyTerminated();
            }
            if (! $fresh->isTerminationScheduled()) {
                throw PeopleException::terminationNotScheduled();
            }
            $this->employees->update($fresh, ['fired_at' => null, 'termination_reason' => null]);
        });
        $this->log->info('people.termination_cancelled', ['id' => $employee->id, 'by' => $actor->id]);

        return $this->records->find($employee->id);
    }

    /**
     * Cron (ScheduledTerminationJob): applies a scheduled termination once its day is over in the user's zone
     * (Kyiv date > fired_at).
     * Idempotent: a row already terminated, cancelled or not yet due is skipped under the row lock.
     */
    public function applyDue(int $employeeId, Carbon $now): bool
    {
        $today = UserTime::today($now)->toDateString();
        $applied = $this->employees->transaction(function () use ($employeeId, $today): bool {
            $fresh = $this->locked($employeeId);
            if (! $fresh->isTerminationScheduled() || $fresh->fired_at?->toDateString() >= $today) {
                return false;
            }
            $this->apply($fresh, null);

            return true;
        });
        if ($applied) {
            $this->log->info('people.employee_terminated', ['id' => $employeeId, 'by' => 'schedule']);
            $this->afterChange($employeeId, true);
        }

        return $applied;
    }

    /**
     * HR only (route gate). Working again from now on; omitted placement fields keep their previous values.
     *
     * @param  array<string, mixed>  $placement  position_id, department_id, branch_id, manager_id, hired_at
     *
     * @throws PeopleException not_terminated | anonymized | manager_cycle
     */
    public function restore(User $actor, Employee $employee, array $placement): Employee
    {
        $this->employees->transaction(function () use ($actor, $employee, $placement): void {
            $fresh = $this->locked($employee->id);
            if (! $fresh->isTerminated()) {
                throw PeopleException::notTerminated();
            }
            if ($fresh->anonymized_at !== null) {
                throw PeopleException::anonymized();
            }
            $blockedVersion = $fresh->termination_block_version;
            $this->records->update($actor, $fresh, [
                ...$placement,
                'status' => EmployeeStatus::Active->value,
                'fired_at' => null,
                'termination_reason' => null,
                'termination_block_version' => null,
            ]);
            // A manual block (before or after the termination) is never lifted here.
            $user = $fresh->user;
            if ($user !== null && $blockedVersion !== null) {
                $this->accounts->unblockIfBlockedBy($user, $blockedVersion, $actor->id);
            }
        });
        $this->log->info('people.employee_restored', ['id' => $employee->id, 'by' => $actor->id]);
        $restored = $this->records->find($employee->id);
        $this->events->dispatch(new EmployeeRestored($restored));

        return $restored;
    }

    /** Inside the transaction: status terminated, then block the login and remember which block was ours. */
    private function apply(Employee $employee, ?int $actorId): void
    {
        $attributes = ['status' => EmployeeStatus::Terminated->value];
        $user = $employee->user;
        if ($user !== null) {
            $attributes['termination_block_version'] = $this->accounts->block($user, $actorId);
        }
        $this->employees->update($employee, $attributes);
    }

    private function afterChange(int $employeeId, bool $terminated): Employee
    {
        $employee = $this->records->find($employeeId);
        if ($terminated) {
            $this->events->dispatch(new EmployeeTerminated($employee));
        }

        return $employee;
    }

    private function locked(int $id): Employee
    {
        $this->employees->lockForUpdate($id);

        return $this->records->find($id);
    }

    private function authorize(PeopleContext $ctx, int $employeeId): void
    {
        if (! $ctx->canTerminate($employeeId)) {
            throw PeopleException::forbidden();
        }
    }
}
