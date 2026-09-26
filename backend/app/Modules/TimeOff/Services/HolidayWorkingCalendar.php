<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Services;

use App\Modules\Core\Contracts\WorkingCalendar;
use App\Modules\TimeOff\Contracts\LeaveSettingsRepository;
use App\Modules\TimeOff\Support\WorkingDayCalculator;
use Illuminate\Support\Carbon;

/** WorkingCalendar on TimeOff holidays (company-wide + branch) and the same Mon–Fri rule as leave day counting. */
final readonly class HolidayWorkingCalendar implements WorkingCalendar
{
    public function __construct(private LeaveSettingsRepository $settings) {}

    public function addWorkingDays(Carbon $from, int $days, ?int $branchId = null): Carbon
    {
        if ($days <= 0) {
            return $from->copy();
        }
        // Enough room for the days plus weekends and a generous number of holidays.
        $to = $from->copy()->addDays($days * 2 + 30);

        return WorkingDayCalculator::addWorkingDays($from, $days, $this->settings->holidayDates($from->copy()->addDay(), $to, $branchId));
    }

    public function isWorkingDay(Carbon $day, ?int $branchId = null): bool
    {
        return WorkingDayCalculator::workingDates($day, $day, $this->settings->holidayDates($day, $day, $branchId)) !== [];
    }
}
