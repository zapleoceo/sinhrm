<?php

declare(strict_types=1);

namespace App\Modules\Core\Transfer;

use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\Query\Builder;

/**
 * Read-only checks before a single row is written. Any blocking finding stops the transfer (there is deliberately
 * no "ignore" switch): fix the data on the source or the schema by a migration, then run again.
 *
 * @phpstan-import-type Table from SchemaInspector
 * @phpstan-import-type UniqueIndex from SchemaInspector
 */
final class Preflight
{
    /** Last second a MySQL TIMESTAMP can hold (and its first one). */
    public const string TIMESTAMP_MAX = '2038-01-19 03:14:07';

    public const string TIMESTAMP_MIN = '1970-01-01 00:00:01';

    /** MySQL JSON nesting limit. */
    private const int JSON_DEPTH = 100;

    /** Ids shown per colliding group / groups shown per index (ids only, never values). */
    private const int SHOW_IDS = 5;

    private const int SHOW_GROUPS = 10;

    public function __construct(
        private readonly TransferDatabases $dbs,
        private readonly SchemaInspector $schema,
        private readonly CollationKeys $keys,
        private readonly int $chunk,
    ) {}

    /**
     * @param  callable(): StringEncrypter  $encrypter
     * @param  bool  $truncate  the run will empty the target first (then rows already on the target do not matter)
     * @param  list<string>  $skip  tables not copied (--without-secrets): no APP_KEY check, no data checks, must stay empty
     */
    public function run(callable $encrypter, bool $truncate = false, array $skip = []): TransferReport
    {
        $report = new TransferReport;
        if (! SchemaCheck::check($this->schema, $report)) {
            return $report; // further checks need identical schemas
        }
        $this->checkSession($report);
        $tables = $this->schema->tables();
        if ($skip === []) {
            KeyCheck::check($this->dbs->source, $encrypter, $report);
        }
        foreach ($skip as $name) {
            if (isset($tables[$name])) {
                $target = $truncate ? 0 : $this->dbs->target->table($name)->count();
                WithoutSecrets::preflight($name, $this->dbs->source->table($name)->count(), $target, $truncate, $report);
                unset($tables[$name]);
            }
        }
        if (! $truncate) {
            foreach ($tables as $table) {
                $this->checkTargetRows($table, $report);
            }
        }
        foreach ($this->schema->uniqueIndexes() as $index) {
            if (isset($tables[$index['table']])) {
                $this->checkCollisions($tables[$index['table']], $index, $report);
            }
        }
        $packet = (int) $this->dbs->target->selectOne('select @@max_allowed_packet as p')->p;
        foreach ($tables as $table) {
            $this->checkTimestamps($table, $report);
            $this->checkLengths($table, $report);
            $this->checkJson($table, $report);
            $this->checkPacket($table, $packet, $report);
        }

        return $report;
    }

    /**
     * Without --truncate-target the copy only ADDS missing rows, so whatever the target already holds must be a part of
     * the source: same primary keys, identical content. Otherwise foreign rows (another environment, migration
     * baseline rows with other timestamps, rows changed after an earlier run) would be mixed into production data.
     *
     * @param  Table  $table
     */
    private function checkTargetRows(array $table, TransferReport $report): void
    {
        $target = $this->dbs->target;
        if (! $target->table($table['name'])->exists()) {
            return;
        }
        if ($table['primary'] === []) {
            if ($target->table($table['name'])->count() !== $this->dbs->source->table($table['name'])->count()) {
                $report->fail('target_not_empty', $table['name'], 'в цели уже есть строки таблицы без первичного ключа — перенос только с --truncate-target');
            }

            return;
        }
        $kinds = array_map(fn (array $c): string => ValueCanonicalizer::kind($c), $table['columns']);
        $foreign = 0;
        $different = 0;
        foreach (RowReader::chunks($target, $table['name'], $table['primary'], RowReader::sizeFor($table, $this->chunk)) as $rows) {
            $sourceRows = [];
            foreach (RowReader::byKeys($this->dbs->source, $table['name'], $table['primary'], $rows) as $row) {
                $sourceRows[RowReader::keyOf($row, $table['primary'])] = $row;
            }
            foreach ($rows as $row) {
                $match = $sourceRows[RowReader::keyOf($row, $table['primary'])] ?? null;
                if ($match === null) {
                    $foreign++;

                    continue;
                }
                foreach ($kinds as $column => $kind) {
                    if (ValueCanonicalizer::canonical($row[$column], $kind) !== ValueCanonicalizer::canonical($match[$column], $kind)) {
                        $different++;

                        break;
                    }
                }
            }
        }
        if ($foreign > 0) {
            $report->fail('target_not_empty', $table['name'], "в цели {$foreign} строк с ключами, которых нет в источнике (чужие данные) — перенос только с --truncate-target", $foreign);
        }
        if ($different > 0) {
            $report->fail('target_not_empty', $table['name'], "в цели {$different} строк отличаются от источника — перенос только с --truncate-target", $different);
        }
    }

    private function checkSession(TransferReport $report): void
    {
        $session = $this->dbs->target->selectOne('select @@session.time_zone as tz, @@session.sql_mode as mode, @@version as v');
        if ((string) $session->tz !== '+00:00') {
            $report->fail('session', '-', 'time_zone сессии MySQL не +00:00');
        }
        foreach (['STRICT_TRANS_TABLES', 'NO_ZERO_DATE', 'NO_ZERO_IN_DATE', 'ERROR_FOR_DIVISION_BY_ZERO'] as $mode) {
            if (! str_contains((string) $session->mode, $mode)) {
                $report->fail('session', '-', "sql_mode сессии MySQL без {$mode} (должен совпадать с рабочим соединением `mysql`)");
            }
        }
        if (! str_starts_with((string) $session->v, '8.4.')) {
            $report->note('session', '-', 'версия MySQL не 8.4 — целевая по ADR 0010 именно 8.4 LTS');
        }
    }

    /**
     * @param  Table  $table
     * @param  UniqueIndex  $index
     */
    private function checkCollisions(array $table, array $index, TransferReport $report): void
    {
        $columns = array_column($index['columns'], 'column');
        $collated = [];
        foreach ($index['columns'] as $part) {
            $column = $table['columns'][$part['column']] ?? null;
            if ($column === null) {
                // Functional key part (column_name is NULL in information_schema): say so instead of skipping silently.
                $report->note('unique_collision', $table['name'], "индекс {$index['name']} по выражению не проверен на коллизии — проверить вручную");

                return;
            }
            $collation = $column['collation'];
            if ($collation !== null && ! str_ends_with($collation, '_bin') && ! str_ends_with($collation, '_cs')) {
                $collated[$part['column']] = ['collation' => $collation, 'sub_part' => $part['sub_part']];
            }
        }
        if ($collated === []) {
            return; // integers / binary collations compare exactly as on PostgreSQL
        }
        $finder = new CollisionFinder;
        $idColumns = $table['primary'];
        $numericId = count($idColumns) === 1
            && in_array($table['columns'][$idColumns[0]]['type'] ?? '', ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'], true);
        $ordinal = 0;
        $select = array_values(array_unique(array_merge($idColumns, $columns)));
        $scope = function (Builder $query) use ($columns): void {
            foreach ($columns as $column) {
                $query->whereNotNull($column); // MySQL unique indexes allow repeated NULLs
            }
        };
        foreach (RowReader::chunks($this->dbs->source, $table['name'], $idColumns, max(1, $this->chunk), $select, $scope) as $rows) {
            $parts = [];
            foreach ($columns as $column) {
                $values = array_map(fn (array $row): string => (string) ValueCanonicalizer::forTarget($row[$column], $table['columns'][$column]['source']), $rows);
                if (isset($collated[$column])) {
                    $sub = $collated[$column]['sub_part'];
                    if ($sub !== null) {
                        $values = array_map(fn (string $v): string => mb_substr($v, 0, $sub), $values);
                    }
                    $values = $this->keys->keys($collated[$column]['collation'], $values);
                }
                $parts[] = $values;
            }
            foreach ($rows as $i => $row) {
                $key = hash('sha256', implode("\x1F", array_map(fn (array $p): string => $p[$i], $parts)));
                $ordinal++;
                // Integer ids are safe to print; string keys (password_reset_tokens.email, sessions.id) are not:
                // then the row number in primary-key order of the source is shown instead.
                $id = $numericId ? (string) $row[$idColumns[0]] : '#'.$ordinal;
                $finder->add($key, $id);
            }
        }
        $groups = $finder->collisions();
        if ($groups === []) {
            return;
        }
        $shown = array_map(
            fn (array $ids): string => '['.implode(', ', array_slice($ids, 0, self::SHOW_IDS)).(count($ids) > self::SHOW_IDS ? ', …' : '').']',
            array_slice($groups, 0, self::SHOW_GROUPS),
        );
        $report->fail(
            'unique_collision',
            $table['name'],
            "индекс {$index['name']} (".implode(', ', $columns).'): '.count($groups).' групп значений совпадут под '
            .implode('/', array_unique(array_column($collated, 'collation'))).($numericId ? '; id строк: ' : '; номера строк (по порядку ключа среди непустых значений): ').implode(' ', $shown).(count($groups) > self::SHOW_GROUPS ? ' …' : ''),
            count($groups),
        );
    }

    /** @param  Table  $table */
    private function checkTimestamps(array $table, TransferReport $report): void
    {
        foreach ($table['columns'] as $column) {
            if ($column['type'] !== 'timestamp' || $column['source'] === '') {
                continue;
            }
            $count = $this->dbs->source->table($table['name'])
                ->where(fn (Builder $q) => $q->where($column['name'], '>', self::TIMESTAMP_MAX)->orWhere($column['name'], '<', self::TIMESTAMP_MIN))
                ->count();
            if ($count > 0) {
                $report->fail('timestamp_range', $table['name'], "{$column['name']}: {$count} значений вне диапазона MySQL TIMESTAMP (1970…2038-01-19) — колонку перевести в dateTime миграцией", $count);
            }
        }
    }

    /** @param  Table  $table */
    private function checkLengths(array $table, TransferReport $report): void
    {
        foreach ($table['columns'] as $column) {
            if (! in_array($column['source'], ['character varying', 'text', 'character'], true)) {
                continue;
            }
            $wrapped = $this->dbs->source->getQueryGrammar()->wrap($column['name']);
            if (in_array($column['type'], ['varchar', 'char'], true) && $column['max_chars'] !== null) {
                $count = $this->dbs->source->table($table['name'])->whereRaw("char_length({$wrapped}) > ?", [$column['max_chars']])->count();
                $limit = "{$column['max_chars']} символов";
            } elseif (in_array($column['type'], ['tinytext', 'text', 'mediumtext'], true) && $column['max_bytes'] !== null) {
                $count = $this->dbs->source->table($table['name'])->whereRaw("octet_length({$wrapped}) > ?", [$column['max_bytes']])->count();
                $limit = "{$column['max_bytes']} байт";
            } else {
                continue;
            }
            if ($count > 0) {
                $report->fail('length', $table['name'], "{$column['name']}: {$count} значений длиннее колонки MySQL ({$limit})", $count);
            }
        }
    }

    /** @param  Table  $table */
    private function checkJson(array $table, TransferReport $report): void
    {
        $names = array_values(array_map(
            fn (array $c): string => $c['name'],
            array_filter($table['columns'], fn (array $c): bool => $c['type'] === 'json'),
        ));
        if ($names === []) {
            return;
        }
        $invalid = array_fill_keys($names, 0);
        $select = array_values(array_unique(array_merge($table['primary'], $names)));
        foreach (RowReader::chunks($this->dbs->source, $table['name'], $table['primary'], RowReader::sizeFor($table, $this->chunk), $select) as $rows) {
            foreach ($rows as $row) {
                foreach ($names as $name) {
                    if ($row[$name] === null) {
                        continue;
                    }
                    json_decode((string) $row[$name], false, self::JSON_DEPTH + 1);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $invalid[$name]++;
                    }
                }
            }
        }
        foreach ($invalid as $name => $count) {
            if ($count > 0) {
                $report->fail('json', $table['name'], "{$name}: {$count} значений не являются JSON, допустимым для MySQL (синтаксис или вложенность > ".self::JSON_DEPTH.')', $count);
            }
        }
    }

    /**
     * One row must fit into one INSERT packet (base64 attachments are the big ones).
     *
     * @param  Table  $table
     */
    private function checkPacket(array $table, int $packet, TransferReport $report): void
    {
        $limit = $packet - 64 * 1024; // statement text and protocol overhead
        $wrapped = $this->dbs->source->getQueryGrammar()->wrapTable($table['name']);
        $max = (int) $this->dbs->source->selectOne("select coalesce(max(octet_length(t::text)), 0) as m from {$wrapped} t")->m;
        if ($max > $limit) {
            $count = (int) $this->dbs->source->selectOne("select count(*) as c from {$wrapped} t where octet_length(t::text) > ?", [$limit])->c;
            $report->fail('packet', $table['name'], "{$count} строк больше max_allowed_packet цели ({$packet} байт) — увеличить max_allowed_packet на MySQL", $count);
        } elseif ($max > intdiv($limit, 2)) {
            $report->note('packet', $table['name'], "самая большая строка {$max} байт при max_allowed_packet {$packet}");
        }
    }
}
