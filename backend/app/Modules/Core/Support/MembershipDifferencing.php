<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

/**
 * Differencing guard over a history of releases of the same groups (pure, no DB, no answers in or out).
 *
 * Attack: a group's aggregate is published in release A and again in release B. If the member sets differ by
 * d people with 1 <= d < min, then sum(B) − sum(A) mixes the answers of fewer than min people (a newcomer; or a
 * "one out, one in" swap where the counts stay equal but d = 2). Equal answer counts do not prove equal members;
 * the member sets do. Rule: a group is visible in a release only if, for every EARLIER release in which the same
 * group was visible, the symmetric difference of the two member sets is 0 or at least min. So no two visible
 * releases of a group are ever "a handful of people" apart, and subtracting any two visible aggregates always mixes
 * at least min people (or none). A hidden release never counts as a base for later ones, which keeps the rule
 * from hiding a group forever when it grows by one person every period.
 *
 * Members are opaque strings (e.g. HMAC fingerprints of employee ids): they only need to be equal for the same
 * person in both releases.
 */
final class MembershipDifferencing
{
    /**
     * @param  list<string|int>  $a
     * @param  list<string|int>  $b
     */
    public static function symmetricDifference(array $a, array $b): int
    {
        $a = array_flip(array_map('strval', $a));
        $b = array_flip(array_map('strval', $b));

        return count(array_diff_key($a, $b)) + count(array_diff_key($b, $a));
    }

    public static function allowed(int $difference, int $minGroup): bool
    {
        return $difference === 0 || $difference >= max(1, $minGroup);
    }

    /**
     * @param  list<array{min: int, groups: array<string, list<string|int>>}>  $releases  chronological, oldest first
     * @return list<array<string, bool>> per release: group key to "may be shown"
     */
    public static function visibility(array $releases): array
    {
        $out = [];
        foreach ($releases as $k => $release) {
            $visible = [];
            foreach ($release['groups'] as $group => $members) {
                $ok = true;
                for ($j = 0; $j < $k && $ok; $j++) {
                    if (! ($out[$j][$group] ?? false)) {
                        continue;
                    }
                    $min = max($release['min'], $releases[$j]['min']);
                    $ok = self::allowed(self::symmetricDifference($members, $releases[$j]['groups'][$group]), $min);
                }
                $visible[$group] = $ok;
            }
            $out[] = $visible;
        }

        return $out;
    }
}
