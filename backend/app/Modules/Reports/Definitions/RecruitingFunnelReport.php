<?php

declare(strict_types=1);

namespace App\Modules\Reports\Definitions;

use App\Modules\Recruiting\Services\ReportService;
use App\Modules\Reports\DTO\ScopedContext;
use App\Modules\Reports\Enums\ReportGroup;

/** Applications created in the range by their current stage, all vacancies together (reuses Recruiting's funnel). */
final class RecruitingFunnelReport extends AbstractReport
{
    public function __construct(private readonly ReportService $recruiting) {}

    public function key(): string
    {
        return 'recruiting_funnel';
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
        return [['key' => 'stage', 'type' => 'string'], ['key' => 'applications', 'type' => 'number']];
    }

    public function chart(): array
    {
        return ['label' => 'stage', 'value' => 'applications'];
    }

    public function available(ScopedContext $ctx): bool
    {
        return $ctx->user->isActive();
    }

    public function rows(ScopedContext $ctx, array $filters): array
    {
        $rows = [];
        /** @var list<array{stage_name: string, position: int, count: int}> $funnel */
        $funnel = $this->recruiting->funnel($ctx->user, self::range($filters, 90), null)['rows'];
        foreach ($funnel as $r) {
            $key = sprintf('%03d|%s', $r['position'], $r['stage_name']);
            $rows[$key] ??= ['stage' => $r['stage_name'], 'applications' => 0];
            $rows[$key]['applications'] += $r['count'];
        }
        ksort($rows);

        return array_values($rows);
    }
}
