<?php

declare(strict_types=1);

namespace App\Modules\Reports\Definitions;

use App\Modules\Recruiting\Services\ReportService;
use App\Modules\Reports\DTO\ScopedContext;

/** Candidates by source with hires and the hire rate (reuses Recruiting's sources report). */
final class SourceEffectivenessReport extends AbstractRecruitingReport
{
    public function __construct(private readonly ReportService $recruiting) {}

    public function key(): string
    {
        return 'source_effectiveness';
    }

    public function columns(): array
    {
        return [['key' => 'source', 'type' => 'string'], ['key' => 'candidates', 'type' => 'number', 'total' => 'sum'], ['key' => 'hired', 'type' => 'number', 'total' => 'sum'], ['key' => 'hire_rate_pct', 'type' => 'percent', 'total' => 'ratio', 'of' => ['hired', 'candidates']]];
    }

    public function chart(): array
    {
        return ['label' => 'source', 'value' => 'candidates'];
    }

    public function rows(ScopedContext $ctx, array $filters): array
    {
        /** @var list<array{source: string, candidates: int, hired: int}> $rows */
        $rows = $this->recruiting->sources($ctx->user, self::range($filters))['rows'];

        return array_map(static fn (array $r): array => $r + ['hire_rate_pct' => self::pct($r['hired'], $r['candidates'])], $rows);
    }
}
