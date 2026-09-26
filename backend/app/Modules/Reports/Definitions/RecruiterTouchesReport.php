<?php

declare(strict_types=1);

namespace App\Modules\Reports\Definitions;

use App\Modules\Recruiting\Services\ReportService;
use App\Modules\Reports\DTO\ScopedContext;
use App\Modules\Reports\Enums\ReportGroup;

/** Touches per recruiter and channel, split into sent via SinHRM vs captured (reuses Recruiting's touches report). */
final class RecruiterTouchesReport extends AbstractReport
{
    public function __construct(private readonly ReportService $recruiting) {}

    public function key(): string
    {
        return 'recruiter_touches';
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
            ['key' => 'channel', 'type' => 'string'],
            ['key' => 'touches', 'type' => 'number'],
            ['key' => 'via_product', 'type' => 'number'],
        ];
    }

    public function chart(): array
    {
        return ['label' => 'recruiter', 'value' => 'touches'];
    }

    public function available(ScopedContext $ctx): bool
    {
        return $ctx->user->isActive();
    }

    public function rows(ScopedContext $ctx, array $filters): array
    {
        /** @var list<array{author_name: string|null, channel: string, via_product: bool, count: int}> $touches */
        $touches = $this->recruiting->touches($ctx->user, self::range($filters, 30))['rows'];
        $rows = [];
        foreach ($touches as $t) {
            $key = ($t['author_name'] ?? '—').'|'.$t['channel'];
            $rows[$key] ??= ['recruiter' => $t['author_name'] ?? '—', 'channel' => $t['channel'], 'touches' => 0, 'via_product' => 0];
            $rows[$key]['touches'] += $t['count'];
            $rows[$key]['via_product'] += $t['via_product'] ? $t['count'] : 0;
        }
        ksort($rows);

        return array_values($rows);
    }
}
