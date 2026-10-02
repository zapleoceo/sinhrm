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
    public const string DEFAULT_TIMEZONE = 'Europe/Kyiv';

    /** The configured zone if it is a valid IANA identifier (typo, empty, missing → Europe/Kyiv, never an exception). */
    public static function timezone(): string
    {
        $tz = config('app.user_timezone');

        return is_string($tz) && in_array($tz, timezone_identifiers_list(), true) ? $tz : self::DEFAULT_TIMEZONE;
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
        return $moment->copy()->setTimezone(self::storageTimezone());
    }

    /**
     * The user's calendar date ("today" in the user's zone) at midnight of the storage zone: the drop-in for
     * Carbon::today() wherever the value is a date — a date column, toDateString(), a comparison with a date cast.
     * Between midnight in the user's zone and midnight UTC, Carbon::today() is still yesterday; this is already today.
     */
    public static function today(?Carbon $now = null): Carbon
    {
        return Carbon::parse(self::now($now)->toDateString(), self::storageTimezone());
    }

    private static function storageTimezone(): string
    {
        $tz = config('app.timezone');

        return is_string($tz) && $tz !== '' ? $tz : 'UTC';
    }
}
