<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Support;

/**
 * Whether two aggregates of the same group in two waves may be shown side by side (pure).
 *
 * Differencing attack: if wave B has the same people as wave A plus one newcomer, then
 * sum(B) − sum(A) is the newcomer's answer. Anonymous responses carry no employee id across waves (the per-wave
 * salt is wiped on close), so the exact overlap is unknown; the answer counts are. At least |nA − nB| people differ,
 * and when that number is between 1 and the minimum group minus 1 the difference isolates fewer than the minimum
 * people, so the comparison is withheld. Equal counts (0) or a difference of at least the minimum are allowed.
 */
final class SafeComparison
{
    public static function allowed(int $now, int $then, int $minGroup): bool
    {
        $diff = abs($now - $then);

        return $diff === 0 || $diff >= max(1, $minGroup);
    }
}
