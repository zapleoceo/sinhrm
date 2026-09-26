<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Services;

use App\Models\User;
use App\Modules\People\Models\Employee;
use App\Modules\TimeOff\Contracts\LeaveRequestRepository;
use App\Modules\TimeOff\Contracts\LeaveSettingsRepository;
use App\Modules\TimeOff\Contracts\LedgerRepository;
use App\Modules\TimeOff\Enums\LedgerReason;
use App\Modules\TimeOff\Models\LeaveType;
use App\Modules\TimeOff\Models\LedgerEntry;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Balances are the sum of the ledger (leave_balance_ledger). Pending requests reserve days: available = balance − pending.
 * Types without balance tracking (sick, unpaid day off) report only the days used this year.
 */
final readonly class BalanceService
{
    public function __construct(
        private LedgerRepository $ledger,
        private LeaveRequestRepository $requests,
        private LeaveSettingsRepository $settings,
        private LoggerInterface $log,
    ) {}

    /** @return list<array<string, mixed>> one row per active leave type */
    public function balances(Employee $employee, Carbon $now): array
    {
        $balances = $this->ledger->balances($employee->id);
        $rows = [];
        foreach ($this->settings->types(false) as $type) {
            $pending = $this->requests->pendingDays($employee->id, $type->id);
            $balance = $type->tracks_balance ? ($balances[$type->id] ?? 0.0) : null;
            $policy = $type->tracks_balance ? $this->settings->policyFor($type->id, $employee->branch_id) : null;
            $rows[] = [
                'leave_type' => ['id' => $type->id, 'name' => $type->name, 'code' => $type->code, 'color' => $type->color, 'paid' => $type->paid],
                'tracked' => $type->tracks_balance,
                'balance' => $balance,
                'pending' => $pending,
                'available' => $balance === null ? null : round($balance - $pending, 2),
                'used_this_year' => $this->requests->usedDays($employee->id, $type->id, $now->year),
                'policy' => $policy === null ? null : [
                    'id' => $policy->id,
                    'accrual_mode' => $policy->accrual_mode->value,
                    'annual_days' => $policy->annualDays(),
                    'carry_over_max' => $policy->carryOverMax(),
                    'branch_id' => $policy->branch_id,
                ],
            ];
        }

        return $rows;
    }

    /** Balance minus other pending requests of the type. */
    public function available(Employee $employee, LeaveType $type, ?int $exceptRequestId = null): float
    {
        return round($this->ledger->balance($employee->id, $type->id) - $this->requests->pendingDays($employee->id, $type->id, $exceptRequestId), 2);
    }

    public function adjust(User $actor, Employee $employee, LeaveType $type, float $delta, ?string $comment): void
    {
        $this->ledger->append([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'delta' => round($delta, 2),
            'reason' => LedgerReason::Adjustment->value,
            'comment' => $comment,
            'created_by' => $actor->id,
        ]);
        $this->log->info('timeoff.balance_adjusted', ['employee' => $employee->id, 'type' => $type->id, 'by' => $actor->id]);
    }

    /** @return list<LedgerEntry> */
    public function history(Employee $employee, ?int $leaveTypeId): array
    {
        return $this->ledger->history($employee->id, $leaveTypeId, 100);
    }
}
