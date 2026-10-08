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
        $extra = count(array_diff($target, $source));
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
}
