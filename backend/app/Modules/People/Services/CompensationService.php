<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Models\User;
use App\Modules\People\Models\Employee;
use App\Modules\People\Models\EmployeeCompensation;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/** Compensation history. Access (HR staff / the employee read-only) is decided by the caller: route gate or own profile. */
final readonly class CompensationService
{
    public function __construct(private LoggerInterface $log) {}

    /** @param  array<string, mixed>  $data  validated SaveCompensationRequest */
    public function add(User $actor, Employee $employee, array $data): EmployeeCompensation
    {
        $record = EmployeeCompensation::query()->create($data + ['employee_id' => $employee->id, 'created_by' => $actor->id]);
        // No amount in the log: salary is personal data.
        $this->log->info('people.compensation_added', ['employee' => $employee->id, 'id' => $record->id, 'by' => $actor->id]);

        return $record;
    }

    /** @return array{current: array<string, mixed>|null, history: list<array<string, mixed>>} */
    public function payload(Employee $employee): array
    {
        $history = EmployeeCompensation::query()->where('employee_id', $employee->id)
            ->orderByDesc('effective_on')->orderByDesc('id')->get();
        $today = Carbon::now()->toDateString();
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
