<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/** Schema facts of the MySQL 8.4 test database (ADR 0010), read from information_schema of the current database. */
trait MysqlSchemaAssertions
{
    protected function assertColumnCollation(string $table, string $column, string $collation): void
    {
        $actual = DB::selectOne(
            'select collation_name as c from information_schema.columns where table_schema = database() and table_name = ? and column_name = ?',
            [$table, $column],
        )?->c;

        $this->assertSame($collation, $actual, "{$table}.{$column}");
    }

    /** @return list<string> column names of the index in key order (an expression part is reported as its SQL) */
    protected function indexParts(string $table, string $index): array
    {
        $rows = DB::select(
            'select column_name as c, expression as e from information_schema.statistics
             where table_schema = database() and table_name = ? and index_name = ? order by seq_in_index',
            [$table, $index],
        );

        return array_map(static fn (object $r): string => (string) ($r->c ?? $r->e), $rows);
    }
}
