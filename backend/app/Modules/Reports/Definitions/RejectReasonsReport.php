<?php

declare(strict_types=1);

namespace App\Modules\Reports\Definitions;

use App\Modules\Recruiting\Services\ReportService;
use App\Modules\Reports\DTO\ScopedContext;
use App\Modules\Reports\Enums\ReportGroup;

/** Rejections in the range by reason (reuses Recruiting's report). */
final class RejectReasonsReport extends AbstractReport
{
    public function __construct(private readonly ReportService $recruiting) {}

    public function key(): string
    {
        return 'reject_reasons';
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
        return [['key' => 'reason', 'type' => 'string'], ['key' => 'rejections', 'type' => 'number']];
    }

    public function chart(): array
    {
        return ['label' => 'reason', 'value' => 'rejections'];
    }

    public function available(ScopedContext $ctx): bool
    {
        return $ctx->user->isActive();
    }

    public function rows(ScopedContext $ctx, array $filters): array
    {
        /** @var list<array{name: string|null, count: int}> $rows */
        $rows = $this->recruiting->rejectReasons($ctx->user, self::range($filters))['rows'];

        return array_map(static fn (array $r): array => ['reason' => $r['name'] ?? '—', 'rejections' => $r['count']], $rows);
    }
}
