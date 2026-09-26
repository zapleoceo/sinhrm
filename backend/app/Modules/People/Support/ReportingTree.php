<?php

declare(strict_types=1);

namespace App\Modules\People\Support;

/** Pure helpers over the employee → manager map (no DB). Safe against cycles in bad data. */
final class ReportingTree
{
    /**
     * Every employee below $rootId (direct and indirect reports), $rootId itself excluded.
     *
     * @param  array<int, int|null>  $managerOf  employee id → manager id
     * @return list<int>
     */
    public static function descendants(array $managerOf, int $rootId): array
    {
        $children = self::children($managerOf);
        $seen = [$rootId => true];
        $queue = [$rootId];
        $result = [];
        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($children[$current] ?? [] as $child) {
                if (isset($seen[$child])) {
                    continue;
                }
                $seen[$child] = true;
                $result[] = $child;
                $queue[] = $child;
            }
        }
        sort($result);

        return $result;
    }

    /**
     * @param  array<int, int|null>  $managerOf
     * @return array<int, list<int>> manager id → direct reports
     */
    public static function children(array $managerOf): array
    {
        $children = [];
        foreach ($managerOf as $id => $managerId) {
            if ($managerId !== null && $managerId !== $id) {
                $children[$managerId][] = $id;
            }
        }

        return $children;
    }
}
