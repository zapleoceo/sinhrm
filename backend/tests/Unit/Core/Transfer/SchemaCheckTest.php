<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Transfer;

use App\Modules\Core\Transfer\SchemaCheck;
use App\Modules\Core\Transfer\TransferReport;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class SchemaCheckTest extends TestCase
{
    public function test_legacy_table_only_on_the_source_is_an_info_line_with_its_row_count(): void
    {
        $report = new TransferReport;
        $counted = [];
        $ok = SchemaCheck::tables(['app_state', 'users'], ['users'], function (string $table) use (&$counted): int {
            $counted[] = $table;

            return 1;
        }, $report);

        $this->assertTrue($ok);
        $this->assertTrue($report->ok());
        $this->assertSame(['app_state'], $counted);
        $this->assertSame([[
            'check' => 'schema', 'table' => 'app_state',
            'detail' => 'устаревшая таблица только в источнике, не переносится: app_state (1 строк)',
            'count' => 1, 'blocking' => false,
        ]], $report->findings());
    }

    public function test_legacy_table_present_on_the_target_is_drift(): void
    {
        $report = new TransferReport;
        $this->assertFalse(SchemaCheck::tables(['app_state', 'users'], ['app_state', 'users'], fn (): int => 1, $report));
        $this->assertCount(1, $report->blocking());
        $this->assertSame('app_state', $report->blocking()[0]['table']);
        $this->assertStringContainsString('LEGACY_SOURCE_ONLY_TABLES есть на цели', $report->blocking()[0]['detail']);

        $report = new TransferReport;
        $this->assertFalse(SchemaCheck::tables(['users'], ['app_state', 'users'], fn (): int => 0, $report));
        $this->assertSame(['таблица есть только в цели', 'таблица из LEGACY_SOURCE_ONLY_TABLES есть на цели (добавлена миграцией?) — убрать её из списка или миграцию'], array_column($report->blocking(), 'detail'));
    }

    public function test_any_other_one_side_table_still_fails(): void
    {
        $report = new TransferReport;
        $this->assertFalse(SchemaCheck::tables(['users', 'zz_source'], ['users', 'zz_target'], fn (): int => 0, $report));
        $this->assertSame(
            [['zz_source', 'таблица есть только в источнике'], ['zz_target', 'таблица есть только в цели']],
            array_map(fn (array $f): array => [$f['table'], $f['detail']], $report->blocking()),
        );

        $report = new TransferReport;
        $this->assertTrue(SchemaCheck::tables(['users'], ['users'], fn (): int => 0, $report));
        $this->assertSame([], $report->findings());
    }

    /** Rule of the list: only tables that no code references (the transfer tool itself excluded). */
    public function test_legacy_tables_are_not_referenced_by_the_code(): void
    {
        $backend = dirname(__DIR__, 4);
        $transfer = realpath($backend.'/app/Modules/Core/Transfer');
        $this->assertNotFalse($transfer);
        $this->assertNotSame([], SchemaCheck::LEGACY_SOURCE_ONLY_TABLES);
        $hits = [];
        foreach (['app', 'database', 'routes', 'config'] as $dir) {
            if (! is_dir($backend.'/'.$dir)) {
                continue;
            }
            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($backend.'/'.$dir, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                $path = (string) $file->getRealPath();
                if ($file->getExtension() !== 'php' || str_starts_with($path, $transfer)) {
                    continue;
                }
                $code = (string) file_get_contents($path);
                foreach (SchemaCheck::LEGACY_SOURCE_ONLY_TABLES as $table) {
                    if (preg_match('/\b'.preg_quote($table, '/').'\b/', $code) === 1) {
                        $hits[] = "{$table}: {$path}";
                    }
                }
            }
        }
        $this->assertSame([], $hits, 'a table referenced by code is not dead: remove it from LEGACY_SOURCE_ONLY_TABLES and migrate it');
    }
}
