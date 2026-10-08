<?php

declare(strict_types=1);

namespace App\Modules\Core\Transfer;

/**
 * Catalog reads for the transfer. The TARGET schema (built by `php artisan migrate` on MySQL) is the reference:
 * its primary keys, unique indexes, foreign keys and column types drive copying, preflight and reconciliation.
 * Source column types come from the PostgreSQL catalog. Read-only on both sides.
 *
 * @phpstan-type Column array{name: string, source: string, type: string, column_type: string, collation: ?string, max_chars: ?int, max_bytes: ?int, auto: bool}
 * @phpstan-type Table array{name: string, columns: array<string, Column>, primary: list<string>}
 * @phpstan-type UniqueIndex array{table: string, name: string, columns: list<array{column: string, sub_part: ?int}>}
 * @phpstan-type ForeignKey array{table: string, name: string, columns: list<string>, parent: string, parent_columns: list<string>}
 */
final class SchemaInspector
{
    /** Laravel's bookkeeping table: written by `migrate` on each side, compared by name, never copied. */
    public const string MIGRATIONS = 'migrations';

    /** @var array<string, Table>|null */
    private ?array $tables = null;

    public function __construct(private readonly TransferDatabases $dbs) {}

    /** @return list<string> */
    public function sourceTables(): array
    {
        $rows = $this->dbs->source->select(
            'select tablename as name from pg_tables where schemaname = current_schema() and tablename <> ? order by tablename',
            [self::MIGRATIONS],
        );

        return array_values(array_map(fn (object $r): string => (string) $r->name, $rows));
    }

    /** @return list<string> */
    public function targetTables(): array
    {
        $rows = $this->dbs->target->select(
            "select table_name as name from information_schema.tables where table_schema = database() and table_type = 'BASE TABLE' and table_name <> ? order by table_name",
            [self::MIGRATIONS],
        );

        return array_values(array_map(fn (object $r): string => (string) $r->name, $rows));
    }

    /** Row count of a source table (SchemaCheck reports LEGACY_SOURCE_ONLY_TABLES with it; never reads values). */
    public function sourceRowCount(string $table): int
    {
        return $this->dbs->source->table($table)->count();
    }

    /** @return list<string> applied migration names on the given side ([] when the table is missing) */
    public function migrations(bool $source): array
    {
        $connection = $source ? $this->dbs->source : $this->dbs->target;
        if (! $connection->getSchemaBuilder()->hasTable(self::MIGRATIONS)) {
            return [];
        }

        return array_values($connection->table(self::MIGRATIONS)->orderBy('migration')->pluck('migration')
            ->map(fn (mixed $m): string => (string) $m)->all());
    }

    /**
     * The only write to the target bookkeeping table: un-record the given migrations (SchemaCheck::requeuePostFreeze).
     *
     * @param  list<string>  $names
     */
    public function forgetTargetMigrations(array $names): int
    {
        if ($names === [] || ! $this->dbs->target->getSchemaBuilder()->hasTable(self::MIGRATIONS)) {
            return 0;
        }

        return $this->dbs->target->table(self::MIGRATIONS)->whereIn('migration', $names)->delete();
    }

    /** @return array<string, Table> tables present on BOTH sides, by name */
    public function tables(): array
    {
        if ($this->tables !== null) {
            return $this->tables;
        }
        $common = array_values(array_intersect($this->sourceTables(), $this->targetTables()));
        $sourceTypes = [];
        foreach ($this->dbs->source->select('select table_name as t, column_name as c, data_type as d from information_schema.columns where table_schema = current_schema()') as $r) {
            $sourceTypes[(string) $r->t][(string) $r->c] = (string) $r->d;
        }
        $tables = [];
        foreach ($common as $name) {
            $tables[$name] = ['name' => $name, 'columns' => [], 'primary' => []];
        }
        $columns = $this->dbs->target->select(
            'select table_name as t, column_name as c, lower(data_type) as d, lower(column_type) as ct, collation_name as coll,
                character_maximum_length as mc, character_octet_length as mb, extra as x
             from information_schema.columns where table_schema = database() order by table_name, ordinal_position',
        );
        foreach ($columns as $r) {
            $table = (string) $r->t;
            if (! isset($tables[$table])) {
                continue;
            }
            $tables[$table]['columns'][(string) $r->c] = [
                'name' => (string) $r->c,
                'source' => $sourceTypes[$table][(string) $r->c] ?? '',
                'type' => (string) $r->d,
                'column_type' => (string) $r->ct,
                'collation' => $r->coll === null ? null : (string) $r->coll,
                'max_chars' => $r->mc === null ? null : (int) $r->mc,
                'max_bytes' => $r->mb === null ? null : (int) $r->mb,
                'auto' => str_contains(strtolower((string) $r->x), 'auto_increment'),
            ];
        }
        $primary = $this->dbs->target->select(
            "select table_name as t, column_name as c from information_schema.key_column_usage
             where table_schema = database() and constraint_name = 'PRIMARY' order by table_name, ordinal_position",
        );
        foreach ($primary as $r) {
            if (isset($tables[(string) $r->t])) {
                $tables[(string) $r->t]['primary'][] = (string) $r->c;
            }
        }

        return $this->tables = $tables;
    }

    /** @return list<string> columns present on one side only, as "table.column" */
    public function columnDrift(): array
    {
        $drift = [];
        $sourceColumns = [];
        foreach ($this->dbs->source->select('select table_name as t, column_name as c from information_schema.columns where table_schema = current_schema()') as $r) {
            $sourceColumns[(string) $r->t][] = (string) $r->c;
        }
        foreach ($this->tables() as $name => $table) {
            $source = $sourceColumns[$name] ?? [];
            $target = array_keys($table['columns']);
            foreach (array_merge(array_diff($source, $target), array_diff($target, $source)) as $column) {
                $drift[] = $name.'.'.$column;
            }
        }

        return $drift;
    }

    /** @return list<UniqueIndex> unique indexes of the target, PRIMARY included */
    public function uniqueIndexes(): array
    {
        $rows = $this->dbs->target->select(
            'select table_name as t, index_name as i, column_name as c, sub_part as p from information_schema.statistics
             where table_schema = database() and non_unique = 0 order by table_name, index_name, seq_in_index',
        );
        $indexes = [];
        foreach ($rows as $r) {
            $key = $r->t.'|'.$r->i;
            $indexes[$key] ??= ['table' => (string) $r->t, 'name' => (string) $r->i, 'columns' => []];
            $indexes[$key]['columns'][] = ['column' => (string) $r->c, 'sub_part' => $r->p === null ? null : (int) $r->p];
        }

        return array_values($indexes);
    }

    /** @return list<ForeignKey> */
    public function foreignKeys(): array
    {
        $rows = $this->dbs->target->select(
            'select table_name as t, constraint_name as n, column_name as c, referenced_table_name as p, referenced_column_name as pc
             from information_schema.key_column_usage
             where table_schema = database() and referenced_table_name is not null order by table_name, constraint_name, ordinal_position',
        );
        $keys = [];
        foreach ($rows as $r) {
            $key = $r->t.'|'.$r->n;
            $keys[$key] ??= ['table' => (string) $r->t, 'name' => (string) $r->n, 'columns' => [], 'parent' => (string) $r->p, 'parent_columns' => []];
            $keys[$key]['columns'][] = (string) $r->c;
            $keys[$key]['parent_columns'][] = (string) $r->pc;
        }

        return array_values($keys);
    }
}
