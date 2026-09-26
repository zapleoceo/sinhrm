<?php

declare(strict_types=1);

namespace App\Modules\Reports\Definitions;

use App\Modules\Recruiting\Services\ReportService;
use App\Modules\Reports\DTO\ScopedContext;
use App\Modules\Reports\Enums\ReportGroup;

/** Candidates by source with hires and the hire rate (reuses Recruiting's sources report). */
final class SourceEffectivenessReport extends AbstractReport
{
    public function __construct(private readonly ReportService $recruiting) {}

    public function key(): string
    {
        return 'source_effectiveness';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Recruiting;
    }

    public function filters(): array
    {
        return [self::FILTER_FROM, self::FILTER_TO];
    }

    public function columns(): array
    {
        return [['key' => 'source', 'type' => 'string'], ['key' => 'candidates', 'type' => 'number'], ['key' => 'hired', 'type' => 'number'], ['key' => 'hire_rate_pct', 'type' => 'percent']];
    }

    public function chart(): array
    {
        return ['label' => 'source', 'value' => 'candidates'];
    }

    public function available(ScopedContext $ctx): bool
    {
        return $ctx->user->isActive();
    }

    public function rows(ScopedContext $ctx, array $filters): array
    {
        /** @var list<array{source: string, candidates: int, hired: int}> $rows */
        $rows = $this->recruiting->sources($ctx->user, self::range($filters))['rows'];

        return array_map(static fn (array $r): array => $r + ['hire_rate_pct' => self::pct($r['hired'], $r['candidates'])], $rows);
    }
}
