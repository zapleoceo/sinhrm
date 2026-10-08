<?php

declare(strict_types=1);

namespace App\Modules\Core\Services\Transfer;

use Generator;
use Illuminate\Database\Connection;

/**
 * Keyset pagination by primary key (single or composite) on either side; tables without a key are streamed.
 *
 * @phpstan-import-type Table from SchemaInspector
 */
final class RowReader
{
    /** Batch for tables with long columns (base64 attachments up to ~2.7 MB per row): keeps memory bounded. */
    public const int LARGE_ROWS_CHUNK = 4;

    /** @param  Table  $table */
    public static function sizeFor(array $table, int $chunk): int
    {
        foreach ($table['columns'] as $column) {
            if (in_array($column['type'], ['longtext', 'longblob', 'mediumtext', 'mediumblob'], true)) {
                return min(max(1, $chunk), self::LARGE_ROWS_CHUNK);
            }
        }

        return max(1, $chunk);
    }

    /**
     * @param  list<string>  $primary
     * @param  list<string>  $columns
     * @return Generator<int, list<array<string, mixed>>>
     */
    public static function chunks(Connection $db, string $table, array $primary, int $size, array $columns = ['*'], ?callable $scope = null): Generator
    {
        if ($primary === []) {
            $batch = [];
            $query = $db->table($table)->select($columns);
            if ($scope !== null) {
                $scope($query);
            }
            foreach ($query->cursor() as $row) {
                $batch[] = (array) $row;
                if (count($batch) >= $size) {
                    yield $batch;
                    $batch = [];
                }
            }
            if ($batch !== []) {
                yield $batch;
            }

            return;
        }

        $grammar = $db->getQueryGrammar();
        $tuple = '('.implode(', ', array_map(fn (string $c): string => $grammar->wrap($c), $primary)).')';
        $placeholders = '('.implode(', ', array_fill(0, count($primary), '?')).')';
        $last = null;
        do {
            $query = $db->table($table)->select($columns)->limit($size);
            foreach ($primary as $column) {
                $query->orderBy($column);
            }
            if ($scope !== null) {
                $scope($query);
            }
            if ($last !== null) {
                $query->whereRaw("{$tuple} > {$placeholders}", $last);
            }
            $rows = array_map(fn (object $r): array => (array) $r, $query->get()->all());
            if ($rows !== []) {
                $tail = $rows[count($rows) - 1];
                $last = array_map(fn (string $c): mixed => $tail[$c], $primary);
                yield $rows;
            }
        } while (count($rows) === $size);
    }
}
