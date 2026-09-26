<?php

declare(strict_types=1);

namespace App\Modules\Channels\Support;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Tolerant readers for provider payloads whose exact format is not guaranteed: the first present value among several
 * dot-paths, times in seconds / milliseconds / date strings, and https-only links. Never throws.
 */
final class Payload
{
    private const int MAX_TEXT = 10000;

    private const int MAX_URL = 1000;

    /**
     * @param  array<array-key, mixed>  $payload
     * @param  list<string>  $paths  dot-paths, e.g. "callDetails.externalNumber"
     */
    public static function str(array $payload, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = data_get($payload, $path);
            if (is_int($value) || is_float($value)) {
                $value = (string) $value;
            }
            if (is_string($value) && trim($value) !== '') {
                return mb_substr(trim($value), 0, self::MAX_TEXT);
            }
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @param  list<string>  $paths
     */
    public static function int(array $payload, array $paths): ?int
    {
        $value = self::str($payload, $paths);

        return $value !== null && is_numeric($value) ? max(0, (int) round((float) $value)) : null;
    }

    /**
     * Unix seconds, unix milliseconds or a date string; missing / unparsable / in the future → now.
     *
     * @param  array<array-key, mixed>  $payload
     * @param  list<string>  $paths
     */
    public static function time(array $payload, array $paths, Carbon $now): Carbon
    {
        $value = self::str($payload, $paths);
        if ($value === null) {
            return $now->copy();
        }
        try {
            $at = match (true) {
                ctype_digit($value) && strlen($value) >= 13 => Carbon::createFromTimestampMs((int) $value),
                ctype_digit($value) => Carbon::createFromTimestamp((int) $value),
                default => Carbon::parse($value),
            };
        } catch (Throwable) {
            return $now->copy();
        }

        return $at->greaterThan($now) ? $now->copy() : $at;
    }

    /**
     * An https link (e.g. a call recording) or null. Only stored as a link: the product never downloads it.
     *
     * @param  array<array-key, mixed>  $payload
     * @param  list<string>  $paths
     */
    public static function httpsUrl(array $payload, array $paths): ?string
    {
        $value = self::str($payload, $paths);
        if ($value === null || mb_strlen($value) > self::MAX_URL || preg_match('/\s/', $value) === 1) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_URL) !== false && str_starts_with(strtolower($value), 'https://') ? $value : null;
    }

    /** @return array<array-key, mixed> */
    public static function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
