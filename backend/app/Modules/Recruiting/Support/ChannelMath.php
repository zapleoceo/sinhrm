<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Support;

use Illuminate\Support\Carbon;

/**
 * Pure math of the channel effectiveness report (tz3).
 * - Cost of a period row inside the report range = amount × overlapping days ÷ days of the period (inclusive dates),
 *   so a monthly contract counted for half a month costs half. Rows are summed per channel.
 * - conversion % = hired ÷ candidates × 100 (one decimal); cost per hire = cost ÷ hired (null when no hires or no cost).
 */
final class ChannelMath
{
    /**
     * @param  list<array{channel_id: int, period_start: string, period_end: string, amount: float}>  $costs
     * @return array<int, float> channel id => prorated cost (2 decimals)
     */
    public static function prorate(array $costs, Carbon $from, Carbon $to): array
    {
        $out = [];
        $rangeStart = $from->copy()->startOfDay();
        $rangeEnd = $to->copy()->startOfDay();
        foreach ($costs as $c) {
            $start = Carbon::parse($c['period_start'])->startOfDay();
            $end = Carbon::parse($c['period_end'])->startOfDay();
            $periodDays = (int) $start->diffInDays($end) + 1;
            $overlapStart = $start->max($rangeStart);
            $overlapEnd = $end->min($rangeEnd);
            if ($periodDays <= 0 || $overlapEnd->lt($overlapStart)) {
                continue;
            }
            $overlapDays = (int) $overlapStart->diffInDays($overlapEnd) + 1;
            $out[$c['channel_id']] = ($out[$c['channel_id']] ?? 0.0) + $c['amount'] * $overlapDays / $periodDays;
        }

        return array_map(static fn (float $v): float => round($v, 2), $out);
    }

    public static function conversion(int $hired, int $candidates): float
    {
        return $candidates === 0 ? 0.0 : round($hired / $candidates * 100, 1);
    }

    public static function costPerHire(?float $cost, int $hired): ?float
    {
        return $cost === null || $hired === 0 ? null : round($cost / $hired, 2);
    }
}
