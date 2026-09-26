<?php

declare(strict_types=1);

namespace App\Modules\Reports\Definitions;

use App\Modules\Reports\Contracts\ReportDataRepository;
use App\Modules\Reports\DTO\ScopedContext;
use App\Modules\Reports\Enums\ReportGroup;
use Illuminate\Support\Carbon;

/**
 * Monthly turnover: terminations of the month ÷ average headcount (first and last day of the month) × 100.
 * The last row is the whole period: terminations ÷ average headcount (start and end of the period).
 */
final class TurnoverReport extends AbstractReport
{
    public function __construct(private readonly ReportDataRepository $data) {}

    public function key(): string
    {
        return 'turnover';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Hr;
    }

    public function filters(): array
    {
        return [self::FILTER_FROM, self::FILTER_TO, self::FILTER_BRANCH];
    }

    public function columns(): array
    {
        return [
            ['key' => 'month', 'type' => 'string'],
            ['key' => 'avg_headcount', 'type' => 'number'],
            ['key' => 'terminations', 'type' => 'number'],
            ['key' => 'turnover_pct', 'type' => 'percent'],
        ];
    }

    public function chart(): array
    {
        return ['label' => 'month', 'value' => 'turnover_pct'];
    }

    public function available(ScopedContext $ctx): bool
    {
        return $ctx->seesTeam();
    }

    public function rows(ScopedContext $ctx, array $filters): array
    {
        $range = self::range($filters);
        $employees = $this->data->employees($ctx->employeeIds(), self::branch($filters));
        $count = static function ($day) use ($employees): int {
            $n = 0;
            foreach ($employees as $e) {
                $n += self::workingOn($e['hired_at'], $e['fired_at'], $day) ? 1 : 0;
            }

            return $n;
        };
        $terminated = static function (string $from, string $to) use ($employees): int {
            $n = 0;
            foreach ($employees as $e) {
                $n += $e['fired_at'] !== null && $e['fired_at'] >= $from && $e['fired_at'] <= $to ? 1 : 0;
            }

            return $n;
        };

        $rows = [];
        foreach (self::months($range) as $m) {
            $start = max($range->from->copy(), Carbon::parse($m.'-01'));
            $end = min($range->to->copy()->startOfDay(), Carbon::parse($m.'-01')->endOfMonth()->startOfDay());
            $avg = ($count($start) + $count($end)) / 2;
            $t = $terminated($start->toDateString(), $end->toDateString());
            $rows[] = ['month' => $m, 'avg_headcount' => round($avg, 1), 'terminations' => $t, 'turnover_pct' => self::pct($t, $avg)];
        }
        $avg = ($count($range->from) + $count($range->to->copy()->startOfDay())) / 2;
        $t = $terminated($range->from->toDateString(), $range->to->toDateString());
        $rows[] = ['month' => 'total', 'avg_headcount' => round($avg, 1), 'terminations' => $t, 'turnover_pct' => self::pct($t, $avg)];

        return $rows;
    }
}
