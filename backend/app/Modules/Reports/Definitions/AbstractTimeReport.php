<?php

declare(strict_types=1);

namespace App\Modules\Reports\Definitions;

use App\Modules\Reports\DTO\ScopedContext;
use App\Modules\Reports\Enums\ReportGroup;
use App\Modules\Time\Services\TimeReportService;

/**
 * Shared base of the Time reports: week rows of the employees in the People scope (admin — all, manager — subtree +
 * self) from Time\Services\TimeReportService (whole weeks, at most 26). Default period: the last 4 weeks.
 */
abstract class AbstractTimeReport extends AbstractReport
{
    protected const int DEFAULT_WEEKS_DAYS = 28;

    public function __construct(private readonly TimeReportService $time) {}

    public function group(): ReportGroup
    {
        return ReportGroup::Hr;
    }

    public function filters(): array
    {
        return [self::FILTER_FROM, self::FILTER_TO, self::FILTER_BRANCH];
    }

    public function available(ScopedContext $ctx): bool
    {
        return $ctx->seesTeam();
    }

    /**
     * @param  array<string, int|string>  $filters
     * @return list<array{employee_id: int, employee: string, department: string|null, week_start: string, status: string, handed_in: bool, expected: float, worked: float, overtime: float, missing: float, absence: float}>
     */
    protected function weeks(ScopedContext $ctx, array $filters): array
    {
        $range = self::range($filters, self::DEFAULT_WEEKS_DAYS);

        return $this->time->weekly($ctx->employeeIds(), $range->from, $range->to, self::branch($filters));
    }

    /**
     * Sums hours of week rows by a key.
     *
     * @param  list<array{employee_id: int, employee: string, department: string|null, week_start: string, status: string, handed_in: bool, expected: float, worked: float, overtime: float, missing: float, absence: float}>  $weeks
     * @param  callable(array{employee_id: int, employee: string, department: string|null, week_start: string, status: string, handed_in: bool, expected: float, worked: float, overtime: float, missing: float, absence: float}): array<string, scalar|null>  $label
     * @return list<array<string, scalar|null>>
     */
    protected static function sum(array $weeks, callable $label): array
    {
        $groups = [];
        foreach ($weeks as $w) {
            $head = $label($w);
            $key = implode('|', array_map('strval', $head));
            $groups[$key] ??= $head + ['expected' => 0.0, 'worked' => 0.0, 'overtime' => 0.0, 'missing' => 0.0, 'absence' => 0.0];
            foreach (['expected', 'worked', 'overtime', 'missing', 'absence'] as $k) {
                $groups[$key][$k] = round((float) $groups[$key][$k] + $w[$k], 2);
            }
        }
        ksort($groups);

        return array_values($groups);
    }
}
