<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Support;

use App\Modules\TimeOff\Enums\AccrualMode;
use Carbon\CarbonInterface;

/** How much a policy grants for a period. Pure: no DB, no clock. */
final class AccrualCalculator
{
    /** Ledger period key: "2026" for yearly up-front grants, "2026-10" for monthly ones. */
    public static function period(AccrualMode $mode, CarbonInterface $now): string
    {
        return $mode === AccrualMode::Monthly ? $now->format('Y-m') : $now->format('Y');
    }

    /**
     * Grant for the period containing $now, or null when the employee is not hired yet in that period.
     * Yearly up front: the full year, prorated by whole months when hired during the year (hire month counts).
     * Monthly: the cumulative target of the year (annual × months worked so far / 12, rounded once) minus what was
     * already accrued this year — so 12 grants of 20/12 sum to exactly 20.00, no rounding drift.
     */
    public static function amount(AccrualMode $mode, float $annualDays, CarbonInterface $hiredAt, CarbonInterface $now, float $accruedThisYear = 0.0): ?float
    {
        if ($mode === AccrualMode::Monthly) {
            if ($hiredAt->gt($now->copy()->endOfMonth())) {
                return null;
            }
            $firstMonth = $hiredAt->year === $now->year ? $hiredAt->month : 1;
            $target = round($annualDays * ($now->month - $firstMonth + 1) / 12, 2);

            return round($target - $accruedThisYear, 2);
        }
        if ($hiredAt->year > $now->year) {
            return null;
        }
        if ($hiredAt->year < $now->year) {
            return round($annualDays, 2);
        }
        $months = 12 - $hiredAt->month + 1;

        return round($annualDays * $months / 12, 2);
    }

    /** Part of the balance that expires on Jan 1 (0 when the carry over is unlimited or not exceeded). */
    public static function expiring(float $balance, ?float $carryOverMax): float
    {
        if ($carryOverMax === null || $balance <= $carryOverMax) {
            return 0.0;
        }

        return round($balance - $carryOverMax, 2);
    }
}
