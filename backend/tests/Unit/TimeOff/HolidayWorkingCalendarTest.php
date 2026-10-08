<?php

declare(strict_types=1);

namespace Tests\Unit\TimeOff;

use App\Modules\TimeOff\Contracts\LeaveSettingsRepository;
use App\Modules\TimeOff\Services\HolidayWorkingCalendar;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Working-day deadlines count Kyiv calendar days and keep the Kyiv wall time (no holidays here; synthetic). */
final class HolidayWorkingCalendarTest extends TestCase
{
    private HolidayWorkingCalendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.user_timezone' => 'Europe/Kyiv']);
        $settings = $this->createStub(LeaveSettingsRepository::class);
        $settings->method('holidayDates')->willReturn([]);
        $this->calendar = new HolidayWorkingCalendar($settings);
    }

    public function test_friday_night_utc_is_saturday_in_kyiv(): void
    {
        // Friday 2026-10-09 21:30 UTC = Saturday 00:30 Kyiv: +2 working days → Tuesday 00:30 Kyiv (Monday 21:30 UTC),
        // not Tuesday 21:30 UTC as counted from the UTC Friday.
        $this->assertSame('2026-10-12 21:30:00', $this->calendar->addWorkingDays(Carbon::parse('2026-10-09 21:30:00', 'UTC'), 2)->format('Y-m-d H:i:s'));
        // Winter: Friday 2026-01-09 22:30 UTC = Saturday 00:30 Kyiv → Tuesday 00:30 Kyiv = Monday 22:30 UTC.
        $this->assertSame('2026-01-12 22:30:00', $this->calendar->addWorkingDays(Carbon::parse('2026-01-09 22:30:00', 'UTC'), 2)->format('Y-m-d H:i:s'));
    }

    public function test_daytime_deadlines_are_unchanged_and_utc(): void
    {
        $due = $this->calendar->addWorkingDays(Carbon::parse('2026-10-16 10:00:00', 'UTC'), 2);
        $this->assertSame('2026-10-20 10:00:00', $due->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $due->getTimezone()->getName());
    }
}
