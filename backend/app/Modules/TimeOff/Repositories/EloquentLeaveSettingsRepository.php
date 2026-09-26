<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Repositories;

use App\Modules\TimeOff\Contracts\LeaveSettingsRepository;
use App\Modules\TimeOff\Models\Holiday;
use App\Modules\TimeOff\Models\LeavePolicy;
use App\Modules\TimeOff\Models\LeaveType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

final class EloquentLeaveSettingsRepository implements LeaveSettingsRepository
{
    public function types(bool $withInactive): Collection
    {
        return LeaveType::query()
            ->when(! $withInactive, fn (Builder $q) => $q->where('active', true))
            ->orderBy('id')
            ->get();
    }

    public function findType(int $id): ?LeaveType
    {
        return LeaveType::query()->find($id);
    }

    public function saveType(?LeaveType $type, array $attributes): LeaveType
    {
        $type ??= new LeaveType;
        $type->fill($attributes)->save();

        return $type;
    }

    public function policies(): Collection
    {
        return LeavePolicy::query()->with(['leaveType', 'branch'])->orderBy('leave_type_id')->orderBy('branch_id')->get();
    }

    public function savePolicy(?LeavePolicy $policy, array $attributes): LeavePolicy
    {
        $policy ??= new LeavePolicy;
        $policy->fill($attributes)->save();

        return $policy->load(['leaveType', 'branch']);
    }

    public function policyFor(int $leaveTypeId, ?int $branchId): ?LeavePolicy
    {
        $policies = LeavePolicy::query()
            ->where('leave_type_id', $leaveTypeId)
            ->where('active', true)
            ->where(fn (Builder $q) => $q->whereNull('branch_id')->when($branchId, fn (Builder $w, int $id) => $w->orWhere('branch_id', $id)))
            ->orderByDesc('id')
            ->get();

        return $policies->first(fn (LeavePolicy $p): bool => $branchId !== null && $p->branch_id === $branchId)
            ?? $policies->first(fn (LeavePolicy $p): bool => $p->branch_id === null);
    }

    public function holidays(?int $year, ?int $branchId): Collection
    {
        return Holiday::query()->with('branch')
            ->when($year, fn (Builder $q, int $y) => $q->whereDate('date', '>=', sprintf('%04d-01-01', $y))->whereDate('date', '<=', sprintf('%04d-12-31', $y)))
            ->when($branchId, fn (Builder $q, int $id) => $q->where(fn (Builder $w) => $w->whereNull('branch_id')->orWhere('branch_id', $id)))
            ->orderBy('date')
            ->get();
    }

    public function saveHoliday(?Holiday $holiday, array $attributes): Holiday
    {
        $holiday ??= new Holiday;
        $holiday->fill($attributes)->save();

        return $holiday->load('branch');
    }

    public function deleteHoliday(Holiday $holiday): void
    {
        $holiday->delete();
    }

    public function holidaysBetween(Carbon $from, Carbon $to, ?int $branchId): Collection
    {
        return Holiday::query()
            // whereDate: SQLite stores date casts as 'Y-m-d H:i:s' strings; Postgres compares real dates.
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->where(fn (Builder $q) => $q->whereNull('branch_id')->when($branchId, fn (Builder $w, int $id) => $w->orWhere('branch_id', $id)))
            ->orderBy('date')
            ->get();
    }

    public function holidayDates(Carbon $from, Carbon $to, ?int $branchId): array
    {
        return $this->holidaysBetween($from, $to, $branchId)
            ->map(static fn (Holiday $h): string => $h->date->toDateString())
            ->unique()
            ->values()
            ->all();
    }
}
