<?php

declare(strict_types=1);

namespace App\Modules\Reports\Definitions;

use App\Modules\Reports\Contracts\ReportDataRepository;
use App\Modules\Reports\DTO\ScopedContext;
use App\Modules\Reports\Enums\ReportGroup;

/**
 * Absence calendar summary per month: how many people were away and the approved days, attributed to the month
 * the request starts in (a request crossing months is counted once, in its first month).
 */
final class AbsencesSummaryReport extends AbstractReport
{
    public function __construct(private readonly ReportDataRepository $data) {}

    public function key(): string
    {
        return 'absences_summary';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Hr;
    }

    public function filters(): array
    {
        return [self::FILTER_FROM, self::FILTER_TO];
    }

    public function columns(): array
    {
        return [['key' => 'month', 'type' => 'string'], ['key' => 'employees_absent', 'type' => 'number'], ['key' => 'days', 'type' => 'number']];
    }

    public function chart(): array
    {
        return ['label' => 'month', 'value' => 'days'];
    }

    public function available(ScopedContext $ctx): bool
    {
        return $ctx->seesTeam();
    }

    public function rows(ScopedContext $ctx, array $filters): array
    {
        $range = self::range($filters);
        $rows = [];
        $people = [];
        foreach (self::months($range) as $m) {
            $rows[$m] = ['month' => $m, 'employees_absent' => 0, 'days' => 0.0];
        }
        foreach ($this->data->approvedLeave($ctx->employeeIds(), $range->from, $range->to) as $r) {
            $month = max(substr($r['starts_on'], 0, 7), $range->from->format('Y-m'));
            if (! isset($rows[$month])) {
                continue;
            }
            $rows[$month]['days'] += $r['days'];
            $people[$month][$r['employee_id']] = true;
        }
        foreach ($rows as $m => &$row) {
            $row['employees_absent'] = count($people[$m] ?? []);
            $row['days'] = round($row['days'], 2);
        }
        unset($row);

        return array_values($rows);
    }
}
