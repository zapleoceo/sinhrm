<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Services;

use App\Modules\People\Contracts\EmployeeRepository;
use App\Modules\People\Models\Employee;
use App\Modules\TimeOff\Contracts\LeaveSettingsRepository;
use App\Modules\TimeOff\Contracts\LedgerRepository;
use App\Modules\TimeOff\Enums\LedgerReason;
use App\Modules\TimeOff\Models\LeaveType;
use App\Modules\TimeOff\Support\AccrualCalculator;
use Illuminate\Support\Carbon;

/**
 * Grants leave by policy. Idempotent per employee / type / period: the ledger's unique (employee, type, reason,
 * period) makes a repeated or overlapping run a no-op.
 * - yearly_upfront: once per year ("2026"), prorated by months when hired during the year;
 * - monthly: once per month ("2026-10"), annual / 12;
 * - Jan 1 (first run of a year): the balance left from previous years above carry_over_max expires ("expiry").
 * Only the current period is granted (a missed month is not back-filled — the cron runs every 30 minutes).
 */
final readonly class AccrualService
{
    public function __construct(
        private EmployeeRepository $employees,
        private LeaveSettingsRepository $settings,
        private LedgerRepository $ledger,
    ) {}

    /** @return array{employees: int, accrued: int, expired: int} */
    public function run(Carbon $now): array
    {
        $types = $this->trackedTypes();
        $totals = ['employees' => 0, 'accrued' => 0, 'expired' => 0];
        foreach ($this->employees->working() as $employee) {
            $totals['employees']++;
            $result = $this->forEmployee($employee, $types, $now);
            $totals['accrued'] += $result['accrued'];
            $totals['expired'] += $result['expired'];
        }

        return $totals;
    }

    /** @return array{accrued: int, expired: int} the first grant right after hire (EmployeeHired) */
    public function accrueFor(Employee $employee, Carbon $now): array
    {
        return $this->forEmployee($employee, $this->trackedTypes(), $now);
    }

    /**
     * @param  list<LeaveType>  $types
     * @return array{accrued: int, expired: int}
     */
    private function forEmployee(Employee $employee, array $types, Carbon $now): array
    {
        $counts = ['accrued' => 0, 'expired' => 0];
        if ($employee->isTerminated() || $employee->hired_at->gt($now->copy()->endOfMonth())) {
            return $counts;
        }
        foreach ($types as $type) {
            $policy = $this->settings->policyFor($type->id, $employee->branch_id);
            if ($policy === null) {
                continue;
            }
            $counts['expired'] += (int) $this->expire($employee, $type, $policy->carryOverMax(), $now);
            $period = AccrualCalculator::period($policy->accrual_mode, $now);
            if ($this->ledger->hasPeriod($employee->id, $type->id, LedgerReason::Accrual, $period)) {
                continue;
            }
            $amount = AccrualCalculator::amount($policy->accrual_mode, $policy->annualDays(), $employee->hired_at, $now);
            if ($amount === null || $amount <= 0) {
                continue;
            }
            $counts['accrued'] += (int) $this->ledger->append([
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'delta' => $amount,
                'reason' => LedgerReason::Accrual->value,
                'period' => $period,
                'created_at' => $now,
            ]);
        }

        return $counts;
    }

    /** Unused balance from previous years above the carry-over limit expires once per year. */
    private function expire(Employee $employee, LeaveType $type, ?float $carryOverMax, Carbon $now): bool
    {
        $yearStart = $now->copy()->startOfYear();
        $period = $now->format('Y');
        if ($carryOverMax === null
            || $this->ledger->hasPeriod($employee->id, $type->id, LedgerReason::Expiry, $period)
            || ! $this->ledger->hasEntriesBefore($employee->id, $type->id, $yearStart)) {
            return false;
        }
        $expiring = AccrualCalculator::expiring($this->ledger->balance($employee->id, $type->id, $yearStart), $carryOverMax);
        if ($expiring <= 0) {
            return false;
        }

        return $this->ledger->append([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'delta' => -$expiring,
            'reason' => LedgerReason::Expiry->value,
            'period' => $period,
            'created_at' => $now,
        ]);
    }

    /** @return list<LeaveType> */
    private function trackedTypes(): array
    {
        return array_values($this->settings->types(false)->filter(static fn (LeaveType $t): bool => $t->tracks_balance)->all());
    }
}
