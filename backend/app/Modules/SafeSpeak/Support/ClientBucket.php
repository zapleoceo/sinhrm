<?php

declare(strict_types=1);

namespace App\Modules\SafeSpeak\Support;

/**
 * Rate-limit bucket of a client WITHOUT the IP: HMAC-SHA256 of the address with the application key, shortened.
 * Only this value reaches the service and the limiter's cache key; the raw address is never stored or logged.
 */
final class ClientBucket
{
    public static function of(?string $ip, string $key): string
    {
        return substr(hash_hmac('sha256', 'safe-speak-ip:'.($ip ?? 'unknown'), $key), 0, 32);
    }
}
