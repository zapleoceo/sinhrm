<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Contracts;

use App\Modules\TimeOff\Enums\LedgerReason;
use App\Modules\TimeOff\Models\LedgerEntry;
use Illuminate\Support\Carbon;

interface LedgerRepository
{
    public function balance(int $employeeId, int $leaveTypeId, ?Carbon $before = null): float;

    /** @return array<int, float> leave type id → balance */
    public function balances(int $employeeId): array;

    public function hasPeriod(int $employeeId, int $leaveTypeId, LedgerReason $reason, string $period): bool;

    /** Sum of monthly accrual rows of the year (periods "YYYY-MM"). */
    public function accruedInYear(int $employeeId, int $leaveTypeId, int $year): float;

    public function hasEntriesBefore(int $employeeId, int $leaveTypeId, Carbon $before): bool;

    /**
     * Appends a row. For period rows a concurrent duplicate is swallowed (unique index) and false is returned.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function append(array $attributes): bool;

    /** @return list<LedgerEntry> newest first */
    public function history(int $employeeId, ?int $leaveTypeId, int $limit): array;
}
