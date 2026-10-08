<?php

declare(strict_types=1);

namespace App\Modules\Reports\Definitions;

use App\Modules\Recruiting\Services\ReportService;
use App\Modules\Reports\DTO\ScopedContext;

/** Rejections in the range by reason (reuses Recruiting's report). */
final class RejectReasonsReport extends AbstractRecruitingReport
{
    public function __construct(private readonly ReportService $recruiting) {}

    public function key(): string
    {
        return 'reject_reasons';
    }

    public function columns(): array
    {
        return [['key' => 'reason', 'type' => 'string'], ['key' => 'rejections', 'type' => 'number', 'total' => 'sum']];
    }

    public function chart(): array
    {
        return ['label' => 'reason', 'value' => 'rejections'];
    }

    public function rows(ScopedContext $ctx, array $filters): array
    {
        /** @var list<array{name: string|null, count: int}> $rows */
        $rows = $this->recruiting->rejectReasons($ctx->user, self::range($filters))['rows'];

        return array_map(static fn (array $r): array => ['reason' => $r['name'] ?? '—', 'rejections' => $r['count']], $rows);
    }
}
