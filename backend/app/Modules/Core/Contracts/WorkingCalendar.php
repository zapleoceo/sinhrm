<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use Illuminate\Support\Carbon;

/**
 * Working-days calendar shared by all modules (approval SLA deadlines, overdue flags, escalations).
 * A working day is Mon–Fri that is not a public holiday (TimeOff holidays: company-wide + the branch's own).
 * Implemented by TimeOff (holidays live there); other modules depend on this contract only.
 */
interface WorkingCalendar
{
    /** Default approval SLA, in working days (owner decision). */
    public const int DEFAULT_SLA_DAYS = 2;

    /**
     * Deadline $days working days after $from, keeping the time of day. Friday 10:00 + 2 → Tuesday 10:00;
     * a start on a weekend/holiday counts from the next working day (Saturday + 1 → Monday, same time).
     * $days <= 0 returns $from unchanged.
     */
    public function addWorkingDays(Carbon $from, int $days, ?int $branchId = null): Carbon;

    public function isWorkingDay(Carbon $day, ?int $branchId = null): bool;
}
