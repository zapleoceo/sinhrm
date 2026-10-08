<?php

declare(strict_types=1);

namespace App\Modules\Core\Transfer;

/**
 * Schema identity source <-> target, shared by the preflight and the reconciliation: migration versions, tables and
 * columns present on one side only. Every difference is a blocking finding — a transfer or a verification over the
 * intersection only would report OK while data has nowhere to go (or is not compared). The only accepted differences are
 * explicit lists: POST_FREEZE_DATA_MIGRATIONS (data migrations extra on the target) and LEGACY_SOURCE_ONLY_TABLES (dead
 * tables only on the source) — both reported as info lines.
 */
final class SchemaCheck
{
    /**
     * Data-only migrations added to main after the Neon freeze (no table or column change, idempotent). They may already
     * be applied on a target migrated by the current release: accepted as "extra on the target" (an info line, not a
     * failure) — tables and columns are still compared exactly. After a copy the command un-records them on the target
     * (requeuePostFreeze), so the next `php artisan migrate` re-runs them over the transferred rows
     * (docs/guides/mysql-cutover.md). Adding a schema migration here is wrong: it would hide real drift.
     *
     * @var list<string>
     */
    public const array POST_FREEZE_DATA_MIGRATIONS = [
        '2026_10_28_100001_mark_sent_offer_touchpoints',
    ];

    /**
     * Dead tables that exist only on the frozen PostgreSQL source and are deliberately not transferred. Rule for the
     * list: a table goes here only when no version of the code references it (git grep over main and
     * legacy/vercel-postgres is empty; SchemaCheckTest guards app/, database/, routes/, config/). Present on the source
     * and absent on the target: an info line with the source row count, not a failure; copy, preflight data checks and
     * --verify never see it (SchemaInspector::tables() is the intersection of both sides). Present on the TARGET too
     * (someone added it by a migration): a failure like any other drift — the list must never hide a real table.
     * app_state — remnant of the earliest prototype (2026-09-25): one row id='main' with {"stages": [...]}.
     *
     * @var list<string>
     */
    public const array LEGACY_SOURCE_ONLY_TABLES = [
        'app_state',
    ];

    /** @return bool true when the target is migrated and both schemas are identical */
    public static function check(SchemaInspector $schema, TransferReport $report): bool
    {
        $source = $schema->migrations(true);
        $target = $schema->migrations(false);
        if ($target === []) {
            $report->fail('schema', '-', 'на цели не выполнены миграции: сначала `php artisan migrate --force` с DB_CONNECTION=mysql');

            return false;
        }
        $ok = true;
        $missing = count(array_diff($source, $target));
        $extraNames = array_diff($target, $source);
        $postFreeze = array_values(array_intersect($extraNames, self::POST_FREEZE_DATA_MIGRATIONS));
        $extra = count($extraNames) - count($postFreeze);
        if ($postFreeze !== []) {
            $report->note('schema', 'migrations', 'миграции данных после заморозки уже на цели (допустимо): '.implode(', ', $postFreeze)
                .' — после переноса будут сняты с учёта и повторены `php artisan migrate --force`', count($postFreeze));
        }
        if ($missing + $extra > 0) {
            $report->fail('schema', 'migrations', "версии схемы расходятся: нет на цели {$missing}, лишних на цели {$extra} — выровнять релиз кода", $missing + $extra);
            $ok = false;
        }
        if (! self::tables($schema->sourceTables(), $schema->targetTables(), $schema->sourceRowCount(...), $report)) {
            $ok = false;
        }
        foreach ($schema->columnDrift() as $column) {
            $report->fail('schema', explode('.', $column)[0], "колонка {$column} есть только на одной стороне");
            $ok = false;
        }

        return $ok;
    }

    /**
     * Table sets source <-> target. A table on one side only is a failure, except a LEGACY_SOURCE_ONLY_TABLES entry
     * present on the source alone (info line with its row count); such an entry on the target is a failure.
     *
     * @param  list<string>  $source
     * @param  list<string>  $target
     * @param  callable(string): int  $sourceRows  row count of a source table (counts only, never values)
     * @return bool true when no blocking difference was found
     */
    public static function tables(array $source, array $target, callable $sourceRows, TransferReport $report): bool
    {
        $ok = true;
        foreach (array_diff($source, $target) as $table) {
            if (in_array($table, self::LEGACY_SOURCE_ONLY_TABLES, true)) {
                $rows = $sourceRows($table);
                $report->note('schema', $table, "устаревшая таблица только в источнике, не переносится: {$table} ({$rows} строк)", $rows);

                continue;
            }
            $report->fail('schema', $table, 'таблица есть только в источнике');
            $ok = false;
        }
        foreach (array_diff($target, $source) as $table) {
            $report->fail('schema', $table, 'таблица есть только в цели');
            $ok = false;
        }
        foreach (array_intersect(self::LEGACY_SOURCE_ONLY_TABLES, $target) as $table) {
            $report->fail('schema', $table, 'таблица из LEGACY_SOURCE_ONLY_TABLES есть на цели (добавлена миграцией?) — убрать её из списка или миграцию');
            $ok = false;
        }

        return $ok;
    }

    /**
     * After a copy: forget the post-freeze data migrations on the target so `php artisan migrate` applies them to the
     * transferred rows (applied before the copy they ran over an empty table). Returns how many were un-recorded.
     */
    public static function requeuePostFreeze(SchemaInspector $schema): int
    {
        return $schema->forgetTargetMigrations(self::POST_FREEZE_DATA_MIGRATIONS);
    }
}
