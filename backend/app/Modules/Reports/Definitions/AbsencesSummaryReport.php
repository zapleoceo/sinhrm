<?php

declare(strict_types=1);

namespace App\Modules\Reports\Definitions;

use App\Modules\Reports\DTO\ScopedContext;

/**
 * Absence calendar summary per month: how many people were away and the approved days, attributed to the month
 * the request starts in (a request crossing months is counted once, in its first month).
 */
final class AbsencesSummaryReport extends AbstractLeaveReport
{
    public function key(): string
    {
        return 'absences_summary';
    }

    public function columns(): array
    {
        return [['key' => 'month', 'type' => 'string'], ['key' => 'employees_absent', 'type' => 'number', 'total' => 'none'], ['key' => 'days', 'type' => 'number', 'total' => 'sum']];
    }

    public function chart(): array
    {
        return ['label' => 'month', 'value' => 'days'];
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

        return array_values(self::countPeople($rows, $people, 'employees_absent'));
    }
}
