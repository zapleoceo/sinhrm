<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Enums;

use Illuminate\Support\Carbon;

/** How a wave repeats: the next wave of a recurring schedule is created when this one closes. */
enum WaveSchedule: string
{
    case Once = 'once';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';

    /** Start of the next wave, null for "once". */
    public function next(Carbon $start): ?Carbon
    {
        return match ($this) {
            self::Once => null,
            self::Weekly => $start->copy()->addWeek(),
            self::Monthly => $start->copy()->addMonthNoOverflow(),
            self::Quarterly => $start->copy()->addMonthsNoOverflow(3),
        };
    }

    /** Longest allowed wave duration in days (a wave must end before the next one starts). */
    public function maxDays(): int
    {
        return match ($this) {
            self::Once => 366,
            self::Weekly => 7,
            self::Monthly => 28,
            self::Quarterly => 90,
        };
    }
}
