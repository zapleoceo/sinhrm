<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Services;

use App\Modules\Core\Contracts\WorkingCalendar;
use App\Modules\Core\Support\UserTime;
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
        // Weekends and holidays are the user's (Kyiv) calendar days, the time of day is the user's wall clock:
        // Monday 01:00 Kyiv is Sunday 22:00 UTC and must count from Monday, not from a "weekend".
        $local = UserTime::now($from);
        // Enough room for the days plus weekends and a generous number of holidays.
        $to = $local->copy()->addDays($days * 2 + 30);
        $holidays = $this->settings->holidayDates(Carbon::parse($local->copy()->addDay()->toDateString()), Carbon::parse($to->toDateString()), $branchId);

        return UserTime::toStorage(WorkingDayCalculator::addWorkingDays($local, $days, $holidays));
    }

    public function isWorkingDay(Carbon $day, ?int $branchId = null): bool
    {
        return WorkingDayCalculator::workingDates($day, $day, $this->settings->holidayDates($day, $day, $branchId)) !== [];
    }
}
