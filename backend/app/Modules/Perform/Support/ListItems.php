<?php

declare(strict_types=1);

namespace App\Modules\Perform\Support;

use Illuminate\Support\Str;

/**
 * Normalizes JSON list items (agenda points, action items, key results, plan goals): keeps a client id when it is a
 * short token, otherwise generates one, so check-ins and toggles can address an item across edits.
 */
final class ListItems
{
    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $defaults  field → default value; only these fields (and id) are kept
     * @return list<array<string, mixed>>
     */
    public static function normalize(array $items, array $defaults): array
    {
        $out = [];
        $seen = [];
        foreach ($items as $item) {
            $id = isset($item['id']) && is_string($item['id']) && preg_match('/^[A-Za-z0-9_-]{1,32}$/', $item['id']) === 1
                ? $item['id'] : null;
            if ($id === null || isset($seen[$id])) {
                $id = Str::lower(Str::random(10));
            }
            $seen[$id] = true;
            $row = ['id' => $id];
            foreach ($defaults as $field => $default) {
                $row[$field] = array_key_exists($field, $item) && $item[$field] !== null ? $item[$field] : $default;
            }
            $out[] = $row;
        }

        return $out;
    }
}
