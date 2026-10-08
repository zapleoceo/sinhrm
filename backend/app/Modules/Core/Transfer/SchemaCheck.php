<?php

declare(strict_types=1);

namespace App\Modules\Core\Transfer;

/**
 * Schema identity source <-> target, shared by the preflight and the reconciliation: migration versions, tables and
 * columns present on one side only. Every difference is a blocking finding — a transfer or a verification over the
 * intersection only would report OK while data has nowhere to go (or is not compared).
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
        $sourceTables = $schema->sourceTables();
        $targetTables = $schema->targetTables();
        foreach (array_diff($sourceTables, $targetTables) as $table) {
            $report->fail('schema', $table, 'таблица есть только в источнике');
            $ok = false;
        }
        foreach (array_diff($targetTables, $sourceTables) as $table) {
            $report->fail('schema', $table, 'таблица есть только в цели');
            $ok = false;
        }
        foreach ($schema->columnDrift() as $column) {
            $report->fail('schema', explode('.', $column)[0], "колонка {$column} есть только на одной стороне");
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
