<?php

declare(strict_types=1);

namespace App\Modules\Reports\Definitions;

use App\Modules\Recruiting\Services\ReportService;
use App\Modules\Reports\DTO\ScopedContext;
use App\Modules\Reports\Enums\ReportGroup;

/**
 * Acquisition channel effectiveness (tz3): candidates of the period by channel → applications → reached the
 * select/hire stages → hired, conversion, prorated cost and cost per hire (cost only for recruiting managers).
 * Reuses Recruiting\Services\ReportService::channels (the same scope as the sources report).
 */
final class ChannelEffectivenessReport extends AbstractReport
{
    public function __construct(private readonly ReportService $recruiting) {}

    public function key(): string
    {
        return 'channel_effectiveness';
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
            ['key' => 'channel', 'type' => 'string'],
            ['key' => 'type', 'type' => 'string'],
            ['key' => 'candidates', 'type' => 'number'],
            ['key' => 'applications', 'type' => 'number'],
            ['key' => 'advanced', 'type' => 'number'],
            ['key' => 'hired', 'type' => 'number'],
            ['key' => 'conversion_pct', 'type' => 'percent'],
            ['key' => 'cost', 'type' => 'number'],
            ['key' => 'cost_per_hire', 'type' => 'number'],
        ];
    }

    public function chart(): array
    {
        return ['label' => 'channel', 'value' => 'candidates'];
    }

    public function available(ScopedContext $ctx): bool
    {
        return $ctx->user->isActive();
    }

    public function rows(ScopedContext $ctx, array $filters): array
    {
        return array_map(static function (array $r): array {
            unset($r['code']);
            $r['channel'] ??= '—';

            return $r;
        }, $this->recruiting->channels($ctx->user, self::range($filters)));
    }
}
