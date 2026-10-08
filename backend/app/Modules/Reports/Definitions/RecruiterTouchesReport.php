<?php

declare(strict_types=1);

namespace App\Modules\Reports\Definitions;

use App\Modules\Recruiting\Services\ReportService;
use App\Modules\Reports\DTO\ScopedContext;

/** Touches per recruiter and channel, split into sent via SinHRM vs captured (reuses Recruiting's touches report). */
final class RecruiterTouchesReport extends AbstractRecruitingReport
{
    public function __construct(private readonly ReportService $recruiting) {}

    public function key(): string
    {
        return 'recruiter_touches';
    }

    public function columns(): array
    {
        return [
            ['key' => 'recruiter', 'type' => 'string'],
            ['key' => 'channel', 'type' => 'string'],
            ['key' => 'touches', 'type' => 'number', 'total' => 'sum'],
            ['key' => 'via_product', 'type' => 'number', 'total' => 'sum'],
        ];
    }

    public function chart(): array
    {
        return ['label' => 'recruiter', 'value' => 'touches'];
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
