<?php

declare(strict_types=1);

namespace Tests\Unit\Recruiting;

use App\Modules\Recruiting\DTO\DateRange;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Report ranges are the user's (Kyiv) days: the default end is the Kyiv today, timestamps compare in Kyiv day bounds. */
final class DateRangeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.user_timezone' => 'Europe/Kyiv']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_default_end_is_the_kyiv_today_in_the_night_window(): void
    {
        foreach (['2026-10-11 21:30:00' => '2026-10-12', '2026-01-11 22:30:00' => '2026-01-12', '2026-12-31 22:30:00' => '2027-01-01'] as $utc => $kyiv) {
            Carbon::setTestNow($utc);
            $range = DateRange::ofDays(null, null, 7);
            $this->assertSame($kyiv, $range->toArray()['to'], $utc);
            $this->assertSame(Carbon::parse($kyiv)->subDays(6)->toDateString(), $range->toArray()['from'], $utc);
        }
    }

    public function test_moments_are_the_kyiv_day_bounds_in_utc(): void
    {
        $summer = DateRange::ofDays('2026-10-12', '2026-10-12');
        $this->assertSame(['2026-10-11 21:00:00', '2026-10-12 20:59:59'], array_map(static fn (Carbon $c): string => $c->format('Y-m-d H:i:s'), $summer->moments()));
        $this->assertSame(['from' => '2026-10-12', 'to' => '2026-10-12'], $summer->toArray(), 'labels stay the requested days');

        $winter = DateRange::ofDays('2026-01-12', '2026-01-31');
        $this->assertSame(['2026-01-11 22:00:00', '2026-01-31 21:59:59'], array_map(static fn (Carbon $c): string => $c->format('Y-m-d H:i:s'), $winter->moments()));
        $this->assertSame('UTC', $winter->moments()[0]->getTimezone()->getName());
    }

    public function test_last_days_keeps_the_end_and_trims_the_start(): void
    {
        $range = DateRange::ofDays('2024-01-01', '2026-10-12')->lastDays(366);
        $this->assertSame(['from' => '2025-10-12', 'to' => '2026-10-12'], $range->toArray());
        $short = DateRange::ofDays('2026-10-01', '2026-10-12');
        $this->assertSame($short, $short->lastDays(366));
    }
}
