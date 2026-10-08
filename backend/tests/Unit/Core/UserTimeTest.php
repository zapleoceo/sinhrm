<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Modules\Core\Support\UserTime;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** The user's day (and "today") vs the UTC storage day (config app.user_timezone), in winter (+02:00) and summer (+03:00). */
final class UserTimeTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_users_day_starts_at_local_midnight_and_goes_to_storage_in_utc(): void
    {
        config(['app.user_timezone' => 'Europe/Kyiv']);

        $winter = UserTime::now(Carbon::parse('2026-01-15 23:30:00', 'UTC'));
        $this->assertSame('2026-01-16T01:30:00+02:00', $winter->toIso8601String());
        $this->assertSame('2026-01-15 22:00:00', UserTime::toStorage($winter->copy()->startOfDay())->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-16 21:59:59', UserTime::toStorage($winter->copy()->endOfDay())->format('Y-m-d H:i:s'));

        Carbon::setTestNow('2026-07-16 00:30:00');
        $summer = UserTime::now();
        $this->assertSame('2026-07-16T03:30:00+03:00', $summer->toIso8601String());
        $this->assertSame('2026-07-15 21:00:00', UserTime::toStorage($summer->copy()->startOfDay())->format('Y-m-d H:i:s'));
    }

    public function test_the_zone_comes_from_config_and_the_input_is_not_changed(): void
    {
        config(['app.user_timezone' => 'UTC']);
        $now = Carbon::parse('2026-07-15 23:30:00', 'UTC');

        $this->assertSame('UTC', UserTime::timezone());
        $this->assertSame('2026-07-15', UserTime::now($now)->toDateString());

        // Empty, a typo or a non-IANA value → Europe/Kyiv, never an exception.
        foreach (['', 'Europe/Kiev ', 'Mars/Olympus', 'GMT+3'] as $broken) {
            config(['app.user_timezone' => $broken]);
            $this->assertSame('Europe/Kyiv', UserTime::timezone(), $broken);
        }
        $this->assertSame('2026-07-16', UserTime::now($now)->toDateString());
        $this->assertSame('UTC', $now->getTimezone()->getName());
    }

    public function test_today_is_the_users_date_at_storage_midnight(): void
    {
        config(['app.user_timezone' => 'Europe/Kyiv']);

        // 22:30 UTC = 00:30 next day in Kyiv (winter, +02:00): Carbon::today() is still the 15th, the user's today is the 16th.
        $late = Carbon::parse('2026-01-15 22:30:00', 'UTC');
        $this->assertSame('2026-01-16 00:00:00 UTC', UserTime::today($late)->format('Y-m-d H:i:s e'));
        // Midday: the same date as Carbon::today(), so a date column compares exactly as before.
        Carbon::setTestNow('2026-07-15 12:00:00');
        $this->assertTrue(UserTime::today()->equalTo(Carbon::today()));
        // Summer (+03:00): from 21:00 UTC the user's day is already the next one.
        $this->assertSame('2026-07-16', UserTime::today(Carbon::parse('2026-07-15 21:00:00', 'UTC'))->toDateString());
        $this->assertSame('2026-07-15', UserTime::today(Carbon::parse('2026-07-15 20:59:59', 'UTC'))->toDateString());
    }

    public function test_the_spring_dst_day_is_23_hours_long(): void
    {
        config(['app.user_timezone' => 'Europe/Kyiv']);

        // 29 Mar 2026: clocks go 03:00 → 04:00 in Kyiv (01:00 UTC). The day starts at +02:00 and ends at +03:00.
        $day = UserTime::now(Carbon::parse('2026-03-28 23:30:00', 'UTC'));
        $this->assertSame('2026-03-29T01:30:00+02:00', $day->toIso8601String());
        $this->assertSame('2026-03-28 22:00:00', UserTime::toStorage($day->copy()->startOfDay())->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-29 20:59:59', UserTime::toStorage($day->copy()->endOfDay())->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-29T05:00:00+03:00', UserTime::now(Carbon::parse('2026-03-29 02:00:00', 'UTC'))->toIso8601String());
    }

    public function test_wall_time_and_end_of_day_of_a_users_date_are_utc_moments(): void
    {
        config(['app.user_timezone' => 'Europe/Kyiv']);

        // Summer (+03:00) and winter (+02:00): 18:00 Kyiv on that date, the last second of that Kyiv date.
        $this->assertSame('2026-10-14 15:00:00 UTC', UserTime::wallTime(Carbon::parse('2026-10-14'), 18)->format('Y-m-d H:i:s e'));
        $this->assertSame('2026-01-14 16:30:00 UTC', UserTime::wallTime(Carbon::parse('2026-01-14'), 18, 30)->format('Y-m-d H:i:s e'));
        $this->assertSame('2026-10-18 20:59:59 UTC', UserTime::endOfDay(Carbon::parse('2026-10-18'))->format('Y-m-d H:i:s e'));
        $this->assertSame('2026-12-31 21:59:59 UTC', UserTime::endOfDay(Carbon::parse('2026-12-31'))->format('Y-m-d H:i:s e'));
        // The date part counts, whatever the zone or time of the input (UserTime::today() of a night moment).
        $nightToday = UserTime::today(Carbon::parse('2026-12-31 22:30:00', 'UTC'));
        $this->assertSame('2027-01-01 21:59:59', UserTime::endOfDay($nightToday)->format('Y-m-d H:i:s'));
    }
}
