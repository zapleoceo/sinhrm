<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use Illuminate\Support\Carbon;

/**
 * "Today" as the user sees it. The database and the API keep UTC (config app.timezone), but the UI shows local time,
 * so day boundaries ("my tasks for today", "Маршрут дня") must follow the user's time zone, not the server's.
 * There is no per-user or per-company time zone setting yet: one zone for everyone, config app.user_timezone
 * (env APP_USER_TIMEZONE, default Europe/Kyiv). DST is handled by the zone itself (+02:00 in winter, +03:00 in summer).
 */
final class UserTime
{
    public static function timezone(): string
    {
        $tz = config('app.user_timezone');

        return is_string($tz) && $tz !== '' ? $tz : 'Europe/Kyiv';
    }

    /** $now (or the current moment) in the user's time zone: startOfDay()/endOfDay() of it are the user's day. */
    public static function now(?Carbon $now = null): Carbon
    {
        return ($now?->copy() ?? Carbon::now())->setTimezone(self::timezone());
    }

    /**
     * The same moment in the storage time zone (app.timezone). Needed before a Carbon goes into a query binding:
     * the query builder formats it as 'Y-m-d H:i:s' without converting, so a local wall time would be compared to UTC.
     */
    public static function toStorage(Carbon $moment): Carbon
    {
        $tz = config('app.timezone');

        return $moment->copy()->setTimezone(is_string($tz) && $tz !== '' ? $tz : 'UTC');
    }
}
