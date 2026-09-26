<?php

declare(strict_types=1);

namespace App\Modules\Reports\Definitions;

use App\Modules\Reports\DTO\ScopedContext;
use App\Modules\Reports\Enums\ReportGroup;
use App\Modules\Scripts\Services\ScriptReportService;

/** How recruiters follow the scripts: evaluations, average score, next step fixed (reuses Scripts' report). */
final class ScriptScoresReport extends AbstractReport
{
    public function __construct(private readonly ScriptReportService $scripts) {}

    public function key(): string
    {
        return 'script_scores';
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
        return [
            ['key' => 'recruiter', 'type' => 'string'],
            ['key' => 'evaluations', 'type' => 'number'],
            ['key' => 'avg_score', 'type' => 'number'],
            ['key' => 'next_step_fixed_pct', 'type' => 'percent'],
        ];
    }

    public function chart(): array
    {
        return ['label' => 'recruiter', 'value' => 'avg_score'];
    }

    public function available(ScopedContext $ctx): bool
    {
        return $ctx->user->isActive();
    }

    public function rows(ScopedContext $ctx, array $filters): array
    {
        /** @var list<array{author_name: string|null, evaluations: int, avg_score: float, next_step_fixed_pct: float}> $recruiters */
        $recruiters = $this->scripts->report($ctx->user, self::range($filters, 30))['recruiters'];

        return array_map(static fn (array $r): array => [
            'recruiter' => $r['author_name'] ?? '—',
            'evaluations' => $r['evaluations'],
            'avg_score' => $r['avg_score'],
            'next_step_fixed_pct' => $r['next_step_fixed_pct'],
        ], $recruiters);
    }
}
