<?php

declare(strict_types=1);

namespace App\Modules\Core\Services\Transfer;

use Closure;
use Illuminate\Database\Query\Builder;
use RuntimeException;

/**
 * Copies every table source -> target preserving ids, in primary-key batches.
 *
 * - FOREIGN_KEY_CHECKS=0 for this session only (cycles such as scripts <-> script_versions need no special order);
 *   orphans are checked afterwards by the Reconciler.
 * - Resumable/idempotent: a row whose primary key already exists on the target is skipped, so a re-run after a crash
 *   continues where it stopped and a second run copies nothing. Rows that exist but differ are NOT overwritten —
 *   the reconciliation reports them, and the operator re-runs with --truncate-target.
 * - Plain INSERT (no INSERT IGNORE): under strict sql_mode any truncation/conversion is an error, not a warning.
 * - AUTO_INCREMENT of each table is set to max(id)+1.
 *
 * @phpstan-import-type Table from SchemaInspector
 */
final class DataCopier
{
    /** Placeholder limit of one prepared statement (65535) with a margin. */
    private const int MAX_PLACEHOLDERS = 60000;

    public function __construct(
        private readonly TransferDatabases $dbs,
        private readonly SchemaInspector $schema,
        private readonly int $chunk,
    ) {}

    /**
     * @param  Closure(string, int, int): void  $progress  table, copied now, rows on source
     * @return array<string, int> table => rows copied in this run
     */
    public function copy(bool $truncate, Closure $progress): array
    {
        $target = $this->dbs->target;
        $packet = (int) $target->selectOne('select @@max_allowed_packet as p')->p;
        $maxBytes = max(1024 * 1024, intdiv($packet, 2));
        $copied = [];
        $target->statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            if ($truncate) {
                foreach (array_keys($this->schema->tables()) as $name) {
                    $target->statement('TRUNCATE TABLE '.$target->getQueryGrammar()->wrapTable($name));
                }
            }
            foreach ($this->schema->tables() as $table) {
                $copied[$table['name']] = $this->copyTable($table, $maxBytes);
                $progress($table['name'], $copied[$table['name']], $this->dbs->source->table($table['name'])->count());
            }
        } finally {
            $target->statement('SET FOREIGN_KEY_CHECKS=1');
        }

        return $copied;
    }

    /** @param  Table  $table */
    private function copyTable(array $table, int $maxBytes): int
    {
        $source = $this->dbs->source;
        $target = $this->dbs->target;
        $primary = $table['primary'];
        $types = array_map(fn (array $c): string => $c['source'], $table['columns']);
        $perStatement = max(1, min($this->chunk, intdiv(self::MAX_PLACEHOLDERS, max(1, count($types)))));

        if ($primary === []) {
            $sourceCount = $source->table($table['name'])->count();
            $targetCount = $target->table($table['name'])->count();
            if ($targetCount === $sourceCount) {
                return 0;
            }
            if ($targetCount !== 0) {
                throw new RuntimeException("Таблица {$table['name']} без первичного ключа перенесена частично — повторите с --truncate-target");
            }
        }

        $copied = 0;
        foreach (RowReader::chunks($source, $table['name'], $primary, RowReader::sizeFor($table, $this->chunk)) as $rows) {
            if ($primary !== []) {
                $rows = $this->withoutExisting($table, $rows);
            }
            $batch = [];
            $bytes = 0;
            foreach ($rows as $row) {
                $converted = [];
                $size = 0;
                foreach ($row as $column => $value) {
                    $converted[$column] = ValueCanonicalizer::forTarget($value, $types[$column] ?? '');
                    $size += is_string($converted[$column]) ? strlen($converted[$column]) : 8;
                }
                if ($batch !== [] && (count($batch) >= $perStatement || $bytes + $size > $maxBytes)) {
                    $target->table($table['name'])->insert($batch);
                    $batch = [];
                    $bytes = 0;
                }
                $batch[] = $converted;
                $bytes += $size;
                $copied++;
            }
            if ($batch !== []) {
                $target->table($table['name'])->insert($batch);
            }
        }
        $this->resetAutoIncrement($table);

        return $copied;
    }

    /**
     * @param  Table  $table
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withoutExisting(array $table, array $rows): array
    {
        $primary = $table['primary'];
        $query = $this->dbs->target->table($table['name'])->select($primary);
        if (count($primary) === 1) {
            $query->whereIn($primary[0], array_column($rows, $primary[0]));
        } else {
            $query->where(function (Builder $q) use ($rows, $primary): void {
                foreach ($rows as $row) {
                    $q->orWhere(function (Builder $one) use ($row, $primary): void {
                        foreach ($primary as $column) {
                            $one->where($column, $row[$column]);
                        }
                    });
                }
            });
        }
        $existing = [];
        foreach ($query->get() as $found) {
            $existing[$this->keyOf((array) $found, $primary)] = true;
        }
        if ($existing === []) {
            return $rows;
        }

        return array_values(array_filter($rows, fn (array $row): bool => ! isset($existing[$this->keyOf($row, $primary)])));
    }

    /**
     * Primary keys are integers in practice; string keys are compared lower-cased like the ci collation. A near-miss
     * here only means a duplicate-key error on insert (the run stops), never a silently lost row.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $primary
     */
    private function keyOf(array $row, array $primary): string
    {
        return implode("\x1F", array_map(fn (string $c): string => mb_strtolower((string) $row[$c]), $primary));
    }

    /** @param  Table  $table */
    private function resetAutoIncrement(array $table): void
    {
        foreach ($table['columns'] as $column) {
            if ($column['auto']) {
                $max = (int) $this->dbs->target->table($table['name'])->max($column['name']);
                $this->dbs->target->statement('ALTER TABLE '.$this->dbs->target->getQueryGrammar()->wrapTable($table['name']).' AUTO_INCREMENT = '.($max + 1));
            }
        }
    }
}
