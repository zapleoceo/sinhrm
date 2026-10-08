<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Models\User;
use App\Modules\Core\Support\UserTime;
use App\Modules\People\Contracts\EmployeeRepository;
use App\Modules\People\DTO\PeopleContext;
use App\Modules\People\Exceptions\PeopleException;
use App\Modules\People\Models\Employee;
use App\Modules\People\Models\EmployeeCompensation;
use App\Modules\People\Support\SelfDecisionAudit;
use Psr\Log\LoggerInterface;

/** Compensation history. Access (HR staff / the employee read-only) is decided by the caller: route gate or own profile. */
final readonly class CompensationService
{
    public function __construct(
        private LoggerInterface $log,
        private EmployeeRepository $employees,
        private SelfDecisionAudit $selfDecisions,
    ) {}

    /**
     * Separation of duties: HR never writes their own compensation row — a raise is signed off by somebody else.
     * Only break-glass (sole superadmin, PeopleContext::canDecideOrBreakGlass) may, audited as self_decision.
     *
     * @param  array<string, mixed>  $data  validated SaveCompensationRequest
     *
     * @throws PeopleException forbidden
     */
    public function add(User $actor, PeopleContext $ctx, Employee $employee, array $data): EmployeeCompensation
    {
        if (! $ctx->canDecideOrBreakGlass($employee->id)) {
            throw PeopleException::forbidden();
        }
        $record = $this->employees->addCompensation($data + ['employee_id' => $employee->id, 'created_by' => $actor->id]);
        // No amount in the log: salary is personal data.
        $this->selfDecisions->record($ctx, $employee->id, 'people.compensation_added', $record->id);
        $this->log->info('people.compensation_added', ['employee' => $employee->id, 'id' => $record->id, 'by' => $actor->id]);

        return $record;
    }

    /** @return array{current: array<string, mixed>|null, history: list<array<string, mixed>>} */
    public function payload(Employee $employee): array
    {
        $history = $this->employees->compensationHistory($employee->id);
        $today = UserTime::today()->toDateString(); // the user's (Kyiv) date: a raise effective today is current from 00:00 Kyiv
        $current = $history->first(static fn (EmployeeCompensation $c): bool => $c->effective_on->toDateString() <= $today);
        $row = static fn (EmployeeCompensation $c): array => [
            'id' => $c->id,
            'amount' => $c->amount,
            'currency' => $c->currency,
            'period' => $c->period,
            'effective_on' => $c->effective_on->toDateString(),
            'reason' => $c->reason,
            'current' => $current?->id === $c->id,
        ];

        return ['current' => $current === null ? null : $row($current), 'history' => array_values($history->map($row)->all())];
    }
}
