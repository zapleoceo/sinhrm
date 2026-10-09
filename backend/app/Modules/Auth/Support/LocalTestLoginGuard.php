<?php

declare(strict_types=1);

namespace App\Modules\Auth\Support;

/** A second local-only gate inside the controller, beyond conditional route registration. */
final class LocalTestLoginGuard
{
    public static function allows(
        string $environment,
        string $sapi,
        ?string $ip,
        string $host,
        bool $enabled,
        string $expectedSecret,
        string $givenSecret,
    ): bool {
        return $environment === 'local'
            && $sapi === 'cli-server'
            && in_array($ip, ['127.0.0.1', '::1'], true)
            && in_array($host, ['127.0.0.1', '::1'], true)
            && $enabled
            && $expectedSecret !== ''
            && hash_equals($expectedSecret, $givenSecret);
    }
}
