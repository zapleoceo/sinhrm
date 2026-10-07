<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Models\User;
use App\Modules\Core\Support\UserTime;
use App\Modules\People\Contracts\EmployeeRepository;
use App\Modules\People\DTO\EmployeeFilter;
use App\Modules\People\DTO\PeopleContext;
use App\Modules\People\DTO\TerminationOutcome;
use App\Modules\People\Enums\EmployeeStatus;
use App\Modules\People\Events\EmployeeRestored;
use App\Modules\People\Events\EmployeeTerminated;
use App\Modules\People\Events\EmployeeTerminationCancelled;
use App\Modules\People\Events\EmployeeTerminationScheduled;
use App\Modules\People\Exceptions\PeopleException;
use App\Modules\People\Models\Employee;
use App\Modules\Users\Contracts\AccountBlocker;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Termination from a date, its cancellation, and restore (owner decisions PROD-12, PROD-14).
 * - fired_at = the last working day. On or before "today" in the user's zone (UserTime, Europe/Kyiv, never the
 *   UTC date) it applies at once; a later date is scheduled: the employee keeps working and access until the END of
 *   that day, ScheduledTerminationJob applies it from 00:00 Kyiv of the next day (owner decision 2026-10-07).
 * - Applying: status terminated, the linked login blocked with every credential revoked (AccountBlocker), then
 *   EmployeeTerminated (Workflows offboarding, Pulse exit survey) after the commit. A failing listener is logged and
 *   the event re-sent by the next cron run (employees.termination_event_pending) until it goes through; failures are
 *   counted (termination_event_attempts) and past EVENT_ATTEMPTS_WARN_AFTER a warning people.termination_event_stuck is logged.
 * - Restore: working again; the login is unblocked only if that termination blocked it and nobody blocked it since.
 * - Scheduling a future date dispatches EmployeeTerminationScheduled (Pulse opens the exit survey while the person still
 *   has access); cancelling dispatches EmployeeTerminationCancelled. Both are isolated: a failing listener is logged.
 * - Optional handover colleague (handover_to_employee_id, person picker rules of the caller, not the employee): stored
 *   on the employee; Workflows opens the task "Прийняти справи" on EmployeeTerminated and closes it on
 *   EmployeeTerminationCancelled / EmployeeRestored (both carry the date).
 * Every change goes through the Employee model, so the audit log records it (who, fired_at, status).
 */
final readonly class TerminationService
{
    /** More failed deliveries of EmployeeTerminated than this in a row → warning people.termination_event_stuck. */
    public const int EVENT_ATTEMPTS_WARN_AFTER = 5;

    public function __construct(
        private EmployeeRepository $employees,
        private EmployeeService $records,
        private AccountBlocker $accounts,
        private Dispatcher $events,
        private LoggerInterface $log,
    ) {}

    /** @throws PeopleException forbidden | invalid_handover | already_terminated | termination_scheduled */
    public function terminate(PeopleContext $ctx, User $actor, Employee $employee, Carbon $firedAt, ?string $reason, ?int $handoverId = null): Employee
    {
        $this->authorize($ctx, $employee->id);
        $this->checkHandover($ctx, $employee, $handoverId);
        $applied = $this->employees->transaction(function () use ($actor, $employee, $firedAt, $reason, $handoverId): bool {
            $fresh = $this->locked($employee->id);
            if ($fresh->isTerminated()) {
                throw PeopleException::alreadyTerminated();
            }
            if ($fresh->isTerminationScheduled()) {
                throw PeopleException::terminationScheduled();
            }
            $this->employees->update($fresh, [
                'fired_at' => $firedAt->toDateString(),
                'termination_reason' => $reason,
                'handover_to_employee_id' => $handoverId,
            ]);
            if ($firedAt->toDateString() > UserTime::today()->toDateString()) {
                return false;
            }
            $this->apply($fresh, $actor->id);

            return true;
        });
        $this->log->info($applied ? 'people.employee_terminated' : 'people.termination_scheduled', ['id' => $employee->id, 'by' => $actor->id]);
        if ($applied) {
            $this->dispatchTerminated($employee->id);
        } else {
            $this->dispatchQuietly(new EmployeeTerminationScheduled($this->records->find($employee->id)), 'people.termination_scheduled_event_failed', $employee->id);
        }

        return $this->records->find($employee->id);
    }

    /** @throws PeopleException forbidden | already_terminated | termination_not_scheduled */
    public function cancel(PeopleContext $ctx, User $actor, Employee $employee): Employee
    {
        $this->authorize($ctx, $employee->id);
        $firedAt = $this->employees->transaction(function () use ($employee): string {
            $fresh = $this->locked($employee->id);
            if ($fresh->isTerminated()) {
                throw PeopleException::alreadyTerminated();
            }
            if (! $fresh->isTerminationScheduled()) {
                throw PeopleException::terminationNotScheduled();
            }
            $firedAt = (string) $fresh->fired_at?->toDateString();
            $this->employees->update($fresh, ['fired_at' => null, 'termination_reason' => null, 'handover_to_employee_id' => null]);

            return $firedAt;
        });
        $this->log->info('people.termination_cancelled', ['id' => $employee->id, 'by' => $actor->id]);
        $cancelled = $this->records->find($employee->id);
        $this->dispatchQuietly(new EmployeeTerminationCancelled($cancelled, $firedAt), 'people.termination_cancelled_event_failed', $employee->id);

        return $cancelled;
    }

    /**
     * Cron (ScheduledTerminationJob): applies a scheduled termination once its day is over in the user's zone
     * (Kyiv date > fired_at).
     * Idempotent: a row already terminated, cancelled or not yet due is skipped under the row lock.
     */
    public function applyDue(int $employeeId, Carbon $now): TerminationOutcome
    {
        $today = UserTime::today($now)->toDateString();
        $outcome = $this->employees->transaction(function () use ($employeeId, $today): TerminationOutcome {
            $fresh = $this->locked($employeeId);
            if (! $fresh->isTerminationScheduled() || $fresh->fired_at?->toDateString() >= $today) {
                return TerminationOutcome::skipped();
            }

            return new TerminationOutcome(true, ! $this->apply($fresh, null));
        });
        if ($outcome->applied) {
            $this->log->info('people.employee_terminated', ['id' => $employeeId, 'by' => 'schedule']);
            $this->dispatchTerminated($employeeId);
        }

        return $outcome;
    }

    /**
     * Cron: re-sends EmployeeTerminated for a terminated employee whose event did not go through (a listener threw).
     * Subscribers are idempotent per employee and date (Workflows: unique run per trigger/anchor; Pulse: one exit
     * survey per termination date), so a repeat after a partial success does not duplicate work.
     * Returns true when sent, false when a listener failed again, null when there was nothing to send.
     */
    public function retryTerminatedEvent(int $employeeId): ?bool
    {
        $employee = $this->records->find($employeeId);
        if (! $employee->isTerminated() || ! $employee->termination_event_pending) {
            return null;
        }

        return $this->dispatchTerminated($employeeId);
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
        $firedAt = $this->employees->transaction(function () use ($actor, $employee, $placement): ?string {
            $fresh = $this->locked($employee->id);
            if (! $fresh->isTerminated()) {
                throw PeopleException::notTerminated();
            }
            if ($fresh->anonymized_at !== null) {
                throw PeopleException::anonymized();
            }
            $blockedVersion = $fresh->termination_block_version;
            $firedAt = $fresh->fired_at?->toDateString();
            $this->records->update($actor, $fresh, [
                ...$placement,
                'status' => EmployeeStatus::Active->value,
                'fired_at' => null,
                'termination_reason' => null,
                'handover_to_employee_id' => null,
                'termination_block_version' => null,
                'termination_event_pending' => false,
            ]);
            // A manual block (before or after the termination) is never lifted here.
            $user = $fresh->user;
            if ($user !== null && $blockedVersion !== null) {
                $this->accounts->unblockIfBlockedBy($user, $blockedVersion, $actor->id);
            }

            return $firedAt;
        });
        $this->employees->markTerminationEventSent($employee->id); // drops the retry counter of the old termination
        $this->log->info('people.employee_restored', ['id' => $employee->id, 'by' => $actor->id]);
        $restored = $this->records->find($employee->id);
        $this->events->dispatch(new EmployeeRestored($restored, $firedAt));

        return $restored;
    }

    /**
     * Inside the transaction: status terminated, the event marked pending, then block the login and remember which
     * block was ours. Returns false when a linked login was not blocked by this termination (already blocked, or the
     * last active superadmin).
     */
    private function apply(Employee $employee, ?int $actorId): bool
    {
        $attributes = ['status' => EmployeeStatus::Terminated->value, 'termination_event_pending' => true];
        $blocked = true;
        $user = $employee->user;
        if ($user !== null) {
            $attributes['termination_block_version'] = $this->accounts->block($user, $actorId);
            $blocked = $attributes['termination_block_version'] !== null;
        }
        $this->employees->update($employee, $attributes);

        return $blocked;
    }

    /**
     * Same rule as the person picker of the caller (EmployeeService::list: scope, working people only), never the
     * employee being terminated.
     *
     * @throws PeopleException invalid_handover
     */
    private function checkHandover(PeopleContext $ctx, Employee $employee, ?int $handoverId): void
    {
        if ($handoverId === null) {
            return;
        }
        if ($handoverId === $employee->id
            || $this->records->list($ctx, new EmployeeFilter(perPage: 1, onlyIds: [$handoverId]))->total() !== 1) {
            throw PeopleException::invalidHandover();
        }
    }

    /** After the commit, isolated: a throwing listener is logged (id only) and never undoes the change. */
    private function dispatchQuietly(object $event, string $logKey, int $employeeId): void
    {
        try {
            $this->events->dispatch($event);
        } catch (Throwable $e) {
            $this->log->error($logKey, ['id' => $employeeId, 'error' => $e::class]);
        }
    }

    /**
     * After the commit: EmployeeTerminated, isolated. A throwing listener never undoes the termination; the flag
     * termination_event_pending stays true and ScheduledTerminationJob re-sends the event on the next cron run.
     */
    private function dispatchTerminated(int $employeeId): bool
    {
        try {
            $this->events->dispatch(new EmployeeTerminated($this->records->find($employeeId)));
        } catch (Throwable $e) {
            $this->log->error('people.termination_event_failed', ['id' => $employeeId, 'error' => $e::class]);
            $attempts = $this->employees->bumpTerminationEventAttempts($employeeId);
            if ($attempts > self::EVENT_ATTEMPTS_WARN_AFTER) {
                // A permanent error would be retried on every cron run forever: make it loud (id and counter only).
                $this->log->warning('people.termination_event_stuck', ['id' => $employeeId, 'attempts' => $attempts]);
            }

            return false;
        }
        $this->employees->markTerminationEventSent($employeeId);

        return true;
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
