<?php

declare(strict_types=1);

namespace App\Modules\Time\Support;

use App\Modules\Time\DTO\Schedule;
use Illuminate\Support\Carbon;

/**
 * Pure week math (no DB). For each day of the week (Monday start):
 *   scheduled = hours_per_day on a working weekday, else 0;
 *   a public holiday → expected 0;
 *   approved leave with fraction f (1 = full day, 0.5 = half day) → expected = scheduled × (1 − f), absence = scheduled × f;
 *   worked = sum of the day's entries.
 * Week: expected = Σ expected, worked = Σ worked, overtime = max(0, worked − expected),
 * missing = max(0, expected − worked). Leave and holidays therefore never count as missing; work on a weekend,
 * holiday or leave day counts towards overtime. Hours are rounded to 2 decimals.
 */
final class WeekCalculator
{
    /** Monday of the ISO week containing the date. */
    public static function weekStart(Carbon|string $date): Carbon
    {
        return ($date instanceof Carbon ? $date->copy() : Carbon::parse($date))->startOfDay()->startOfWeek(Carbon::MONDAY);
    }

    /**
     * @param  array<string, float>  $worked  Y-m-d => hours
     * @param  array<string, array{type: string, fraction: float}>  $leave  Y-m-d => approved leave on that day
     * @param  list<string>  $holidays  Y-m-d
     * @return array{days: list<array{date: string, weekday: int, scheduled: float, expected: float, worked: float, holiday: bool, leave: array{type: string, fraction: float}|null, absence: float}>, expected: float, worked: float, overtime: float, missing: float, absence: float}
     */
    public static function week(Carbon $weekStart, Schedule $schedule, array $worked, array $leave, array $holidays): array
    {
        $days = [];
        $expected = 0.0;
        $total = 0.0;
        $absence = 0.0;
        for ($i = 0; $i < 7; $i++) {
            $day = $weekStart->copy()->addDays($i);
            $date = $day->toDateString();
            $weekday = $day->dayOfWeekIso;
            $scheduled = in_array($weekday, $schedule->days, true) ? $schedule->hoursPerDay : 0.0;
            $holiday = in_array($date, $holidays, true);
            $dayLeave = $leave[$date] ?? null;
            $dayExpected = $holiday ? 0.0 : $scheduled;
            $dayAbsence = 0.0;
            if ($dayLeave !== null && ! $holiday) {
                $fraction = max(0.0, min(1.0, $dayLeave['fraction']));
                $dayAbsence = round($scheduled * $fraction, 2);
                $dayExpected = round($scheduled - $dayAbsence, 2);
            }
            $dayWorked = round($worked[$date] ?? 0.0, 2);
            $days[] = [
                'date' => $date,
                'weekday' => $weekday,
                'scheduled' => $scheduled,
                'expected' => $dayExpected,
                'worked' => $dayWorked,
                'holiday' => $holiday,
                'leave' => $dayLeave,
                'absence' => $dayAbsence,
            ];
            $expected += $dayExpected;
            $total += $dayWorked;
            $absence += $dayAbsence;
        }

        return [
            'days' => $days,
            'expected' => round($expected, 2),
            'worked' => round($total, 2),
            'overtime' => round(max(0.0, $total - $expected), 2),
            'missing' => round(max(0.0, $expected - $total), 2),
            'absence' => round($absence, 2),
        ];
    }
}
