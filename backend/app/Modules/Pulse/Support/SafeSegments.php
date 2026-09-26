<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Support;

/**
 * Which branch/department groups of a wave may be shown next to the total (pure).
 *
 * A group is shown only if it has at least the minimum number of answers. That alone is not enough: the total minus
 * the shown groups is the aggregate of everybody else, so the hidden remainder must also be empty or at least the
 * minimum. While it is not, the smallest shown group is hidden too (its answers join the remainder). Hence for every
 * shown group both it and its complement within the wave are at least the minimum, and no hidden group can be
 * obtained by subtraction. Hidden groups are not listed at all (no name, no count).
 */
final class SafeSegments
{
    /**
     * @template T
     *
     * @param  array<int, list<T>>  $groups  segment id to its answers
     * @param  int|null  $total  answers in the whole wave (default: all grouped answers)
     * @return array<int, list<T>> the groups that may be shown
     */
    public static function allowed(array $groups, int $minGroup, ?int $total = null): array
    {
        $minGroup = max(1, $minGroup);
        $total ??= array_sum(array_map('count', $groups));
        $shown = array_filter($groups, static fn (array $g): bool => count($g) >= $minGroup);
        while ($shown !== []) {
            $remainder = $total - array_sum(array_map('count', $shown));
            if ($remainder === 0 || $remainder >= $minGroup) {
                break;
            }
            uasort($shown, static fn (array $a, array $b): int => count($a) <=> count($b));
            $smallest = array_key_first($shown);
            unset($shown[$smallest]);
        }

        return $shown;
    }
}
