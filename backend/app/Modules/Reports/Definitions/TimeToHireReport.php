<?php

declare(strict_types=1);

namespace App\Modules\Reports\Definitions;

use App\Modules\Reports\Contracts\ReportDataRepository;
use App\Modules\Reports\DTO\ScopedContext;
use App\Modules\Reports\Enums\ReportGroup;
use Illuminate\Support\Carbon;

/** Days from application to hire (closed in the range) per vacancy: count, average and median. */
final class TimeToHireReport extends AbstractReport
{
    public function __construct(private readonly ReportDataRepository $data) {}

    public function key(): string
    {
        return 'time_to_hire';
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
        return [['key' => 'vacancy', 'type' => 'string'], ['key' => 'hires', 'type' => 'number'], ['key' => 'avg_days', 'type' => 'number'], ['key' => 'median_days', 'type' => 'number']];
    }

    public function chart(): array
    {
        return ['label' => 'vacancy', 'value' => 'avg_days'];
    }

    public function available(ScopedContext $ctx): bool
    {
        return $ctx->user->isActive();
    }

    public function rows(ScopedContext $ctx, array $filters): array
    {
        $range = self::range($filters);
        $days = [];
        foreach ($this->data->hiredApplications($ctx->recruiting, $range->from, $range->to) as $a) {
            $days[$a['vacancy']][] = Carbon::parse($a['created_at'])->startOfDay()->diffInDays(Carbon::parse($a['closed_at'])->startOfDay());
        }
        ksort($days);
        $rows = [];
        foreach ($days as $vacancy => $list) {
            sort($list);
            $n = count($list);
            $median = $n % 2 === 1 ? $list[intdiv($n, 2)] : ($list[$n / 2 - 1] + $list[$n / 2]) / 2;
            $rows[] = ['vacancy' => (string) $vacancy, 'hires' => $n, 'avg_days' => round(array_sum($list) / $n, 1), 'median_days' => round((float) $median, 1)];
        }

        return $rows;
    }
}
