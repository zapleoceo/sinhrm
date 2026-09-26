<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Support;

use App\Modules\TimeOff\Enums\HalfDay;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Working days of a leave: Mon–Fri, minus public holidays. A half day takes 0.5 off the first (start) or the last
 * (end) day when that day is a working day; a one-day half-day leave is 0.5. Also moves a date forward by N working days (approval SLA deadlines). Pure: no DB, no clock.
 * A span over MAX_SPAN_DAYS throws (never a silently truncated count).
 */
final class WorkingDayCalculator
{
    /** Longest leave the calculator walks through (protects the loop from absurd input). */
    public const int MAX_SPAN_DAYS = 366;

    /**
     * @param  list<string>  $holidays  Y-m-d dates
     * @return list<string> working dates (Y-m-d) of the range
     */
    public static function workingDates(CarbonInterface $from, CarbonInterface $to, array $holidays): array
    {
        if ($from->diffInDays($to) > self::MAX_SPAN_DAYS) {
            throw new InvalidArgumentException('Leave span exceeds '.self::MAX_SPAN_DAYS.' days');
        }
        $skip = array_fill_keys($holidays, true);
        $dates = [];
        $day = CarbonImmutable::parse($from->toDateString());
        $end = CarbonImmutable::parse($to->toDateString());
        for (; $day->lte($end); $day = $day->addDay()) {
            if ($day->isWeekend() || isset($skip[$day->toDateString()])) {
                continue;
            }
            $dates[] = $day->toDateString();
        }

        return $dates;
    }

    /**
     * $from moved forward by $days working days, time of day kept (Friday 10:00 + 2 → Tuesday 10:00).
     * Throws when the holidays leave no room within MAX_SPAN_DAYS (never a silently wrong deadline).
     *
     * @param  list<string>  $holidays  Y-m-d dates
     */
    public static function addWorkingDays(Carbon $from, int $days, array $holidays): Carbon
    {
        $skip = array_fill_keys($holidays, true);
        $day = $from->copy();
        for ($left = $days, $walked = 0; $left > 0; $walked++) {
            if ($walked > self::MAX_SPAN_DAYS) {
                throw new InvalidArgumentException('No working days within '.self::MAX_SPAN_DAYS.' days');
            }
            $day = $day->addDay();
            if (! $day->isWeekend() && ! isset($skip[$day->toDateString()])) {
                $left--;
            }
        }

        return $day;
    }

    /** @param  list<string>  $holidays  Y-m-d dates */
    public static function days(CarbonInterface $from, CarbonInterface $to, HalfDay $halfDay, array $holidays): float
    {
        if ($to->lt($from)) {
            return 0.0;
        }
        $dates = self::workingDates($from, $to, $holidays);
        $days = (float) count($dates);
        if ($halfDay === HalfDay::None || $dates === []) {
            return $days;
        }
        $edge = $halfDay === HalfDay::Start ? $from->toDateString() : $to->toDateString();

        return in_array($edge, $dates, true) ? $days - 0.5 : $days;
    }
}
