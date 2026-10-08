<?php

declare(strict_types=1);

namespace App\Modules\Core\Transfer;

use Illuminate\Database\Connection;

/**
 * Read-only reconciliation source <-> target, result OK/FAIL. The report holds table/column names and counters only.
 *
 * - schema identity (SchemaCheck: migrations, tables and columns on one side only);
 * - row count per table;
 * - per-column checksum: XOR of sha256(primary key + canonical value) over all rows — order-independent (MySQL and
 *   PostgreSQL sort strings differently) and sensitive to any changed cell; tables without a primary key get one
 *   checksum over the sorted row hashes;
 * - attachments: sha256 of the decoded base64 content on the target equals its stored sha256 column;
 * - foreign keys: no orphans on the target (the copy ran with FOREIGN_KEY_CHECKS=0);
 * - AUTO_INCREMENT = max(id) + 1 on the target;
 * - --without-secrets: the skipped tables (WithoutSecrets) hold 0 rows on the target; only counts are read.
 *
 * @phpstan-import-type Table from SchemaInspector
 */
final class Reconciler
{
    public function __construct(
        private readonly TransferDatabases $dbs,
        private readonly SchemaInspector $schema,
        private readonly int $chunk,
    ) {}

    /** @param  list<string>  $skip  tables not copied (--without-secrets): expected EMPTY on the target, values never read */
    public function run(array $skip = []): TransferReport
    {
        $report = new TransferReport;
        // Same schema gate as the preflight: a table or column on one side only, or other migration versions, is a
        // FAIL — comparing just the intersection would report OK for data that has nowhere to go.
        SchemaCheck::check($this->schema, $report);
        foreach ($this->schema->tables() as $table) {
            if (in_array($table['name'], $skip, true)) {
                $source = $this->dbs->source->table($table['name'])->count();
                $target = $this->dbs->target->table($table['name'])->count();
                $report->table($table['name'], $source, $target, WithoutSecrets::reconcile($table['name'], $source, $target, $report));

                continue;
            }
            $source = $this->digest($this->dbs->source, $table);
            $target = $this->digest($this->dbs->target, $table);
            $differ = [];
            foreach ($source['columns'] as $column => $sum) {
                if (($target['columns'][$column] ?? null) !== $sum) {
                    $differ[] = (string) $column;
                }
            }
            $countsMatch = $source['count'] === $target['count'];
            if (! $countsMatch) {
                $report->fail('rows', $table['name'], "строк: источник {$source['count']}, цель {$target['count']}", abs($source['count'] - $target['count']));
            }
            if ($differ !== []) {
                $report->fail('checksum', $table['name'], 'контрольные суммы расходятся в колонках: '.implode(', ', $differ), count($differ));
            }
            $report->table($table['name'], $source['count'], $target['count'], $countsMatch && $differ === []);
            $this->checkAttachments($table, $report);
        }
        $this->checkForeignKeys($report);
        $this->checkAutoIncrement($report);

        return $report;
    }

    /**
     * @param  Table  $table
     * @return array{count: int, columns: array<string, string>}
     */
    public function digest(Connection $db, array $table): array
    {
        // Only columns present on both sides (a target-only column has no source type; drift is reported by SchemaCheck).
        $common = array_filter($table['columns'], fn (array $c): bool => $c['source'] !== '');
        $kinds = array_map(fn (array $c): string => ValueCanonicalizer::kind($c), $common);
        $primary = $table['primary'];
        $sums = array_fill_keys(array_keys($kinds), str_repeat("\0", 32));
        $rowHashes = [];
        $count = 0;
        foreach (RowReader::chunks($db, $table['name'], $primary, RowReader::sizeFor($table, $this->chunk), array_keys($kinds)) as $rows) {
            foreach ($rows as $row) {
                $count++;
                if ($primary === []) {
                    $cells = [];
                    foreach ($kinds as $column => $kind) {
                        $cells[] = ValueCanonicalizer::canonical($row[$column], $kind);
                    }
                    $rowHashes[] = hash('sha256', implode("\x1E", $cells), true);

                    continue;
                }
                $key = implode("\x1F", array_map(fn (string $c): string => ValueCanonicalizer::canonical($row[$c], $kinds[$c]), $primary));
                foreach ($kinds as $column => $kind) {
                    $sums[$column] ^= hash('sha256', $key."\x1E".ValueCanonicalizer::canonical($row[$column], $kind), true);
                }
            }
        }
        if ($primary === []) {
            sort($rowHashes, SORT_STRING);

            return ['count' => $count, 'columns' => ['*' => hash('sha256', implode('', $rowHashes))]];
        }

        return ['count' => $count, 'columns' => array_map(fn (string $s): string => bin2hex($s), $sums)];
    }

    /**
     * Attachment pairs: content+sha256 (documents_files, desk_attachments) and <p>_content+<p>_sha256 (career CVs).
     *
     * @param  Table  $table
     */
    private function checkAttachments(array $table, TransferReport $report): void
    {
        if ($table['primary'] === []) {
            return;
        }
        foreach (array_keys($table['columns']) as $column) {
            if (! str_ends_with($column, 'sha256')) {
                continue;
            }
            $content = substr($column, 0, -strlen('sha256')).'content';
            if (! isset($table['columns'][$content])) {
                continue;
            }
            $checked = 0;
            $bad = 0;
            $select = array_values(array_unique(array_merge($table['primary'], [$column, $content])));
            foreach (RowReader::chunks($this->dbs->target, $table['name'], $table['primary'], RowReader::sizeFor($table, $this->chunk), $select) as $rows) {
                foreach ($rows as $row) {
                    if ($row[$content] === null) {
                        continue;
                    }
                    $checked++;
                    $bytes = base64_decode((string) $row[$content], true);
                    if ($bytes === false || hash('sha256', $bytes) !== (string) $row[$column]) {
                        $bad++;
                    }
                }
            }
            if ($bad > 0) {
                $report->fail('attachments', $table['name'], "{$content}: SHA-256 не совпадает у {$bad} из {$checked} вложений", $bad);
            } elseif ($checked > 0) {
                $report->note('attachments', $table['name'], "{$content}: SHA-256 совпадает у {$checked} вложений", $checked);
            }
        }
    }

    private function checkForeignKeys(TransferReport $report): void
    {
        $target = $this->dbs->target;
        $grammar = $target->getQueryGrammar();
        foreach ($this->schema->foreignKeys() as $fk) {
            $on = [];
            $notNull = [];
            foreach ($fk['columns'] as $i => $column) {
                $on[] = 'c.'.$grammar->wrap($column).' = p.'.$grammar->wrap($fk['parent_columns'][$i]);
                $notNull[] = 'c.'.$grammar->wrap($column).' is not null';
            }
            $orphans = (int) $target->selectOne(
                'select count(*) as n from '.$grammar->wrapTable($fk['table']).' c left join '.$grammar->wrapTable($fk['parent']).' p on '
                .implode(' and ', $on).' where '.implode(' and ', $notNull).' and p.'.$grammar->wrap($fk['parent_columns'][0]).' is null',
            )->n;
            if ($orphans > 0) {
                $report->fail('foreign_key', $fk['table'], "{$fk['name']}: {$orphans} строк ссылаются на отсутствующие {$fk['parent']}", $orphans);
            }
        }
    }

    private function checkAutoIncrement(TransferReport $report): void
    {
        $target = $this->dbs->target;
        $target->statement('SET SESSION information_schema_stats_expiry = 0'); // otherwise AUTO_INCREMENT is a cached value
        $counters = [];
        foreach ($target->select('select table_name as t, auto_increment as a from information_schema.tables where table_schema = database() and auto_increment is not null') as $r) {
            $counters[(string) $r->t] = (int) $r->a;
        }
        foreach ($this->schema->tables() as $table) {
            foreach ($table['columns'] as $column) {
                if (! $column['auto'] || ! isset($counters[$table['name']])) {
                    continue;
                }
                $expected = (int) $target->table($table['name'])->max($column['name']) + 1;
                if ($counters[$table['name']] !== $expected) {
                    $report->fail('auto_increment', $table['name'], "AUTO_INCREMENT {$counters[$table['name']]}, ожидается max(id)+1 = {$expected}");
                }
            }
        }
    }
}
