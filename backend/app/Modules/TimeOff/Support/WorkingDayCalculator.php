<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Support;

use App\Modules\TimeOff\Enums\HalfDay;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Working days of a leave: Mon–Fri, minus public holidays. A half day takes 0.5 off the first (start) or the last
 * (end) day when that day is a working day; a one-day half-day leave is 0.5. Pure: no DB, no clock.
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
        $skip = array_fill_keys($holidays, true);
        $dates = [];
        $day = CarbonImmutable::parse($from->toDateString());
        $end = CarbonImmutable::parse($to->toDateString());
        for ($i = 0; $day->lte($end) && $i <= self::MAX_SPAN_DAYS; $i++, $day = $day->addDay()) {
            if ($day->isWeekend() || isset($skip[$day->toDateString()])) {
                continue;
            }
            $dates[] = $day->toDateString();
        }

        return $dates;
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
