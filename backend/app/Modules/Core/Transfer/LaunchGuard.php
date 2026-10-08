<?php

declare(strict_types=1);

namespace App\Modules\Core\Transfer;

/** Protection against a run against production by accident. Pure decisions; the command asks and prints. */
final class LaunchGuard
{
    private const array LOCAL_HOSTS = ['127.0.0.1', 'localhost', '::1', '[::1]', ''];

    /** Loopback aliases (any 127.x address, localhost, ::1, [::1], empty) collapse to one value; other hosts lower-cased. */
    public static function normalizeHost(string $host): string
    {
        return self::isLoopback($host) ? 'loopback' : strtolower(trim($host, '[] '));
    }

    public static function isLoopback(string $host): bool
    {
        $host = strtolower(trim($host));

        return in_array($host, self::LOCAL_HOSTS, true) || str_starts_with($host, '127.') || $host === '0:0:0:0:0:0:0:1';
    }

    /**
     * --production is required when the app runs as production or either database is not on this machine
     * (Neon, the IT STEP MySQL): every real database counts as production for this tool.
     *
     * @param  list<string>  $hosts
     */
    public static function needsProductionFlag(string $appEnv, array $hosts): bool
    {
        if ($appEnv === 'production') {
            return true;
        }
        foreach ($hosts as $host) {
            if (! self::isLoopback($host)) {
                return true;
            }
        }

        return false;
    }

    /** The operator confirms a write by typing the exact target database name (or passing it in --confirm-target). */
    public static function confirmed(?string $typed, string $targetDatabase): bool
    {
        return $typed !== null && $targetDatabase !== '' && hash_equals($targetDatabase, trim($typed));
    }
}
