<?php

declare(strict_types=1);

namespace App\Modules\Core\Transfer;

/**
 * Comparison keys of strings under a target collation: two strings get the same key exactly when the collation
 * considers them equal (e.g. "a@x" and "A@x", "jose" and "josé" under utf8mb4_0900_ai_ci). Used by the transfer
 * preflight to find unique values that would collide on MySQL. Keys are opaque and never printed.
 */
interface CollationKeys
{
    /**
     * @param  list<string>  $values
     * @return list<string> one key per value, same order
     */
    public function keys(string $collation, array $values): array;
}
