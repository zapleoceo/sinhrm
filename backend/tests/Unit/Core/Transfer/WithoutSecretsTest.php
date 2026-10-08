<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Transfer;

use App\Modules\Core\Transfer\KeyCheck;
use App\Modules\Core\Transfer\TransferReport;
use App\Modules\Core\Transfer\WithoutSecrets;
use PHPUnit\Framework\TestCase;

final class WithoutSecretsTest extends TestCase
{
    public function test_option_off_skips_nothing_and_on_skips_every_encrypted_table(): void
    {
        $this->assertSame([], WithoutSecrets::tables(false));
        $this->assertSame(array_keys(KeyCheck::ENCRYPTED), WithoutSecrets::tables(true));
        $this->assertContains('integration_secrets', WithoutSecrets::tables(true));
    }

    public function test_preflight_notes_the_skip_and_blocks_rows_left_on_the_target_without_truncate(): void
    {
        $report = new TransferReport;
        WithoutSecrets::preflight('integration_secrets', 7, 0, false, $report);
        $this->assertTrue($report->ok());
        $this->assertSame(7, $report->findings()[0]['count']);
        $this->assertStringContainsString('в источнике 7 строк, на цели останется пусто', $report->findings()[0]['detail']);

        $report = new TransferReport;
        WithoutSecrets::preflight('integration_secrets', 7, 3, false, $report);
        $this->assertFalse($report->ok());
        $this->assertSame(WithoutSecrets::CHECK, $report->blocking()[0]['check']);
        $this->assertStringContainsString('--truncate-target', $report->blocking()[0]['detail']);

        $report = new TransferReport;
        WithoutSecrets::preflight('integration_secrets', 7, 3, true, $report);
        $this->assertTrue($report->ok(), 'the copy empties the target first');
    }

    public function test_reconciliation_expects_an_empty_target(): void
    {
        $report = new TransferReport;
        $this->assertTrue(WithoutSecrets::reconcile('integration_secrets', 5, 0, $report));
        $this->assertTrue($report->ok());

        $report = new TransferReport;
        $this->assertFalse(WithoutSecrets::reconcile('integration_secrets', 5, 2, $report));
        $this->assertSame(['check' => WithoutSecrets::CHECK, 'table' => 'integration_secrets', 'detail' => '--without-secrets: на цели 2 строк, ожидается 0', 'count' => 2, 'blocking' => true], $report->blocking()[0]);
    }

    public function test_summary_lists_tables_with_source_counts_only(): void
    {
        $this->assertSame(
            'Пропущены таблицы (--without-secrets, на цели пусто): integration_secrets (в источнике 4 строк)',
            WithoutSecrets::summary(['integration_secrets' => 4]),
        );
        $this->assertStringEndsWith(': нет', WithoutSecrets::summary([]));
    }
}
