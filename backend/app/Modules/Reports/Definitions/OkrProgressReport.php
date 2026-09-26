<?php

declare(strict_types=1);

namespace App\Modules\Reports\Definitions;

use App\Modules\Reports\Contracts\ReportDataRepository;
use App\Modules\Reports\DTO\ScopedContext;
use App\Modules\Reports\Enums\ReportGroup;

/**
 * Objectives by scope: count, average progress, achieved. Admins see every objective; managers only personal/team
 * objectives owned by their people (self included) — company/branch objectives and private visibility rules of
 * Perform are not widened here (counts only, no titles).
 */
final class OkrProgressReport extends AbstractReport
{
    public function __construct(private readonly ReportDataRepository $data) {}

    public function key(): string
    {
        return 'okr_progress';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Performance;
    }

    public function filters(): array
    {
        return [self::FILTER_PERIOD];
    }

    public function columns(): array
    {
        return [
            ['key' => 'scope', 'type' => 'string'],
            ['key' => 'objectives', 'type' => 'number'],
            ['key' => 'avg_progress', 'type' => 'percent'],
            ['key' => 'achieved', 'type' => 'number'],
        ];
    }

    public function chart(): array
    {
        return ['label' => 'scope', 'value' => 'avg_progress'];
    }

    public function available(ScopedContext $ctx): bool
    {
        return $ctx->seesTeam();
    }

    public function rows(ScopedContext $ctx, array $filters): array
    {
        $period = isset($filters['period']) ? (string) $filters['period'] : null;
        $rows = [];
        foreach ($this->data->objectives($ctx->employeeIds(), $period) as $o) {
            $rows[$o['scope']] ??= ['scope' => $o['scope'], 'objectives' => 0, 'progress_sum' => 0, 'achieved' => 0];
            $rows[$o['scope']]['objectives']++;
            $rows[$o['scope']]['progress_sum'] += $o['progress'];
            $rows[$o['scope']]['achieved'] += $o['status'] === 'achieved' ? 1 : 0;
        }
        ksort($rows);

        return array_values(array_map(static fn (array $r): array => [
            'scope' => $r['scope'],
            'objectives' => $r['objectives'],
            'avg_progress' => round($r['progress_sum'] / $r['objectives'], 1),
            'achieved' => $r['achieved'],
        ], $rows));
    }
}
