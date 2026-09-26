<?php

declare(strict_types=1);

namespace Tests\Unit\Time;

use App\Modules\Time\DTO\Schedule;
use App\Modules\Time\Support\WeekCalculator;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/** Overtime and missing hours vs the schedule, leave and holidays as absence. */
final class WeekCalculatorTest extends TestCase
{
    public function test_week_start_is_monday(): void
    {
        $this->assertSame('2026-10-05', WeekCalculator::weekStart('2026-10-11')->toDateString());
        $this->assertSame('2026-10-05', WeekCalculator::weekStart('2026-10-05')->toDateString());
    }

    public function test_overtime_missing_leave_and_holiday(): void
    {
        $start = Carbon::parse('2026-10-05');
        $schedule = Schedule::fallback();
        $worked = ['2026-10-05' => 3.0, '2026-10-06' => 10.0, '2026-10-07' => 8.0, '2026-10-10' => 4.0];
        $leave = ['2026-10-08' => ['type' => 'Vacation', 'fraction' => 1.0], '2026-10-09' => ['type' => 'Vacation', 'fraction' => 0.5]];
        $w = WeekCalculator::week($start, $schedule, $worked, $leave, ['2026-10-05']);

        // Expected: Mon holiday 0 + Tue 8 + Wed 8 + Thu leave 0 + Fri half 4 = 20. Worked 25 (holiday and Saturday count).
        $this->assertSame(20.0, $w['expected']);
        $this->assertSame(25.0, $w['worked']);
        $this->assertSame(5.0, $w['overtime']);
        $this->assertSame(0.0, $w['missing']);
        $this->assertSame(12.0, $w['absence']);
        $this->assertTrue($w['days'][0]['holiday']);
        $this->assertSame(4.0, $w['days'][4]['expected']);
        $this->assertSame(0.0, $w['days'][5]['scheduled']);

        $empty = WeekCalculator::week($start, new Schedule([1, 2, 3], 7.5, 'employee'), [], [], []);
        $this->assertSame(22.5, $empty['missing']);
        $this->assertSame(0.0, $empty['overtime']);
    }

    public function test_schedule_parsing(): void
    {
        $this->assertNull(Schedule::fromArray(null, 'x'));
        $this->assertNull(Schedule::fromArray(['days' => [1]], 'x'));
        $s = Schedule::fromArray(['days' => [5, 1, 9, 1], 'hours_per_day' => '6'], 'employee');
        $this->assertNotNull($s);
        $this->assertSame([1, 5], $s->days);
        $this->assertSame(6.0, $s->hoursPerDay);
    }
}
