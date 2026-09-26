<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Contracts;

use App\Modules\TimeOff\Models\Holiday;
use App\Modules\TimeOff\Models\LeavePolicy;
use App\Modules\TimeOff\Models\LeaveType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/** Leave types, policies and public holidays (admin-managed settings). */
interface LeaveSettingsRepository
{
    /** @return Collection<int, LeaveType> */
    public function types(bool $withInactive): Collection;

    public function findType(int $id): ?LeaveType;

    /** @param  array<string, mixed>  $attributes */
    public function saveType(?LeaveType $type, array $attributes): LeaveType;

    /** @return Collection<int, LeavePolicy> */
    public function policies(): Collection;

    /** @param  array<string, mixed>  $attributes */
    public function savePolicy(?LeavePolicy $policy, array $attributes): LeavePolicy;

    /** Active policy of the branch for the type, else the active company default (branch_id null). */
    public function policyFor(int $leaveTypeId, ?int $branchId): ?LeavePolicy;

    /** @return Collection<int, Holiday> */
    public function holidays(?int $year, ?int $branchId): Collection;

    /** @param  array<string, mixed>  $attributes */
    public function saveHoliday(?Holiday $holiday, array $attributes): Holiday;

    public function deleteHoliday(Holiday $holiday): void;

    /**
     * Holidays between the dates that apply to the branch (company-wide + the branch's own), by date.
     *
     * @return Collection<int, Holiday>
     */
    public function holidaysBetween(Carbon $from, Carbon $to, ?int $branchId): Collection;

    /**
     * Holiday dates (Y-m-d) between the dates that apply to the branch (company-wide + the branch's own).
     *
     * @return list<string>
     */
    public function holidayDates(Carbon $from, Carbon $to, ?int $branchId): array;
}
