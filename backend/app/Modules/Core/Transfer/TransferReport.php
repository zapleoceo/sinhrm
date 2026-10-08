<?php

declare(strict_types=1);

namespace App\Modules\Core\Transfer;

/**
 * Findings of preflight / reconciliation. By contract an entry carries only check names, table/column/index names,
 * counters and row ids — never cell values (no personal data, no secrets in the console or CI logs).
 *
 * @phpstan-type Finding array{check: string, table: string, detail: string, count: int, blocking: bool}
 * @phpstan-type TableLine array{table: string, source: int, target: int, ok: bool}
 */
final class TransferReport
{
    /** @var list<Finding> */
    private array $findings = [];

    /** @var list<TableLine> */
    private array $tables = [];

    public function fail(string $check, string $table, string $detail, int $count = 1): void
    {
        $this->findings[] = ['check' => $check, 'table' => $table, 'detail' => $detail, 'count' => $count, 'blocking' => true];
    }

    public function note(string $check, string $table, string $detail, int $count = 0): void
    {
        $this->findings[] = ['check' => $check, 'table' => $table, 'detail' => $detail, 'count' => $count, 'blocking' => false];
    }

    public function table(string $table, int $source, int $target, bool $ok): void
    {
        $this->tables[] = ['table' => $table, 'source' => $source, 'target' => $target, 'ok' => $ok];
    }

    public function ok(): bool
    {
        return $this->blocking() === [];
    }

    /** @return list<Finding> */
    public function blocking(): array
    {
        return array_values(array_filter($this->findings, fn (array $f): bool => $f['blocking']));
    }

    /** @return list<Finding> */
    public function findings(): array
    {
        return $this->findings;
    }

    /** @return list<TableLine> */
    public function tables(): array
    {
        return $this->tables;
    }
}
