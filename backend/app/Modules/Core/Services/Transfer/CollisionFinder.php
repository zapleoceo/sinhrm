<?php

declare(strict_types=1);

namespace App\Modules\Core\Services\Transfer;

/**
 * Groups row ids by collation key; a group of two or more ids = values that are distinct on PostgreSQL but equal
 * on MySQL, i.e. a unique-index violation waiting to happen. Holds ids only, never the values.
 */
final class CollisionFinder
{
    /** @var array<string, list<string>> */
    private array $groups = [];

    public function add(string $key, string $rowId): void
    {
        $this->groups[$key][] = $rowId;
    }

    /** @return list<list<string>> */
    public function collisions(): array
    {
        return array_values(array_filter($this->groups, fn (array $ids): bool => count($ids) > 1));
    }
}
