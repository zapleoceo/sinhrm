<?php

declare(strict_types=1);

namespace App\Modules\Core\Services\Transfer;

/** Protection against a run against production by accident. Pure decisions; the command asks and prints. */
final class LaunchGuard
{
    private const array LOCAL_HOSTS = ['127.0.0.1', 'localhost', '::1', ''];

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
            if (! in_array(strtolower($host), self::LOCAL_HOSTS, true)) {
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
