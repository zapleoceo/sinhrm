<?php

declare(strict_types=1);

namespace App\Modules\Core\Services\Transfer;

use App\Modules\Core\Contracts\CollationKeys;
use Illuminate\Database\Connection;
use InvalidArgumentException;

/**
 * Exact keys from the target server itself: WEIGHT_STRING(value COLLATE <collation>). Equal weight strings = equal
 * under the collation (case, accents, NO PAD trailing spaces — whatever MySQL decides). Read-only SELECTs.
 */
final class MysqlCollationKeys implements CollationKeys
{
    private const int PER_QUERY = 200;

    public function __construct(private readonly Connection $target) {}

    public function keys(string $collation, array $values): array
    {
        if (! preg_match('/^[a-z0-9_]+$/', $collation)) {
            throw new InvalidArgumentException('Unexpected collation name');
        }
        $keys = [];
        foreach (array_chunk($values, self::PER_QUERY) as $chunk) {
            $columns = [];
            foreach (array_keys($chunk) as $i) {
                $columns[] = "hex(weight_string(convert(? using utf8mb4) collate {$collation})) as k{$i}";
            }
            $row = (array) $this->target->selectOne('select '.implode(', ', $columns), $chunk);
            foreach (array_keys($chunk) as $i) {
                $keys[] = (string) $row['k'.$i];
            }
        }

        return $keys;
    }
}
