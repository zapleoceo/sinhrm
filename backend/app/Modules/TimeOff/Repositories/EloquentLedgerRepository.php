<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Repositories;

use App\Modules\TimeOff\Contracts\LedgerRepository;
use App\Modules\TimeOff\Enums\LedgerReason;
use App\Modules\TimeOff\Models\LedgerEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class EloquentLedgerRepository implements LedgerRepository
{
    public function balance(int $employeeId, int $leaveTypeId, ?Carbon $before = null): float
    {
        $sum = LedgerEntry::query()
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
            ->when($before, fn (Builder $q, Carbon $b) => $q->where('created_at', '<', $b))
            ->sum('delta');

        return round((float) $sum, 2);
    }

    public function balances(int $employeeId): array
    {
        $rows = LedgerEntry::query()
            ->where('employee_id', $employeeId)
            ->groupBy('leave_type_id')
            ->toBase()
            ->get(['leave_type_id', DB::raw('sum(delta) as total')]);
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->leave_type_id] = round((float) $row->total, 2);
        }

        return $result;
    }

    public function hasPeriod(int $employeeId, int $leaveTypeId, LedgerReason $reason, string $period): bool
    {
        return LedgerEntry::query()
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
            ->where('reason', $reason->value)
            ->where('period', $period)
            ->exists();
    }

    public function accruedInYear(int $employeeId, int $leaveTypeId, int $year): float
    {
        return round((float) LedgerEntry::query()
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
            ->where('reason', LedgerReason::Accrual->value)
            ->where('period', 'like', sprintf('%04d-%%', $year))
            ->sum('delta'), 2);
    }

    public function hasEntriesBefore(int $employeeId, int $leaveTypeId, Carbon $before): bool
    {
        return LedgerEntry::query()
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
            ->where('created_at', '<', $before)
            ->exists();
    }

    public function append(array $attributes): bool
    {
        $attributes['created_at'] ??= Carbon::now();
        try {
            // Savepoint: on Postgres a failed INSERT would otherwise abort the surrounding transaction.
            DB::transaction(static fn () => LedgerEntry::query()->create($attributes));
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    public function history(int $employeeId, ?int $leaveTypeId, int $limit): array
    {
        return array_values(LedgerEntry::query()
            ->where('employee_id', $employeeId)
            ->when($leaveTypeId, fn (Builder $q, int $id) => $q->where('leave_type_id', $id))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->all());
    }
}
