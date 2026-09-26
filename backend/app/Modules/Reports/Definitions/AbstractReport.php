<?php

declare(strict_types=1);

namespace App\Modules\Reports\Definitions;

use App\Modules\Recruiting\DTO\DateRange;
use App\Modules\Reports\Contracts\ReportDefinition;
use Illuminate\Support\Carbon;

/** Shared helpers of catalog reports (date ranges, months, percentages). No data access here. */
abstract class AbstractReport implements ReportDefinition
{
    /** Default period of range filters, days. */
    protected const int DEFAULT_DAYS = 365;

    public function filters(): array
    {
        return [];
    }

    public function chart(): ?array
    {
        return null;
    }

    /** @param  array<string, mixed>  $filters */
    protected static function range(array $filters, int $defaultDays = self::DEFAULT_DAYS): DateRange
    {
        $from = isset($filters['from']) ? (string) $filters['from'] : null;
        $to = isset($filters['to']) ? (string) $filters['to'] : null;

        return DateRange::ofDays($from, $to, $defaultDays);
    }

    /**
     * Month keys "YYYY-MM" from the range start to its end, inclusive.
     *
     * @return list<string>
     */
    protected static function months(DateRange $range): array
    {
        $months = [];
        for ($m = $range->from->copy()->startOfMonth(); $m->lte($range->to); $m->addMonth()) {
            $months[] = $m->format('Y-m');
        }

        return $months;
    }

    protected static function pct(float|int $part, float|int $total): float
    {
        return $total == 0 ? 0.0 : round($part / $total * 100, 1);
    }

    /** Working on the day: hired on or before it and not fired before it. */
    protected static function workingOn(string $hiredAt, ?string $firedAt, Carbon $day): bool
    {
        $d = $day->toDateString();

        return $hiredAt <= $d && ($firedAt === null || $firedAt > $d);
    }

    /** @param  array<string, mixed>  $filters */
    protected static function branch(array $filters): ?int
    {
        return isset($filters['branch_id']) ? (int) $filters['branch_id'] : null;
    }
}
