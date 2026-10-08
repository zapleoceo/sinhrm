<?php

declare(strict_types=1);

namespace App\Modules\Reports\Definitions;

use App\Modules\Reports\DTO\ScopedContext;

/** Approved leave by type: requests and days (requests overlapping the range count whole). */
final class LeaveUsageReport extends AbstractLeaveReport
{
    public function key(): string
    {
        return 'leave_usage';
    }

    public function columns(): array
    {
        return [['key' => 'leave_type', 'type' => 'string'], ['key' => 'requests', 'type' => 'number', 'total' => 'sum'], ['key' => 'days', 'type' => 'number', 'total' => 'sum'], ['key' => 'employees', 'type' => 'number', 'total' => 'none']];
    }

    public function chart(): array
    {
        return ['label' => 'leave_type', 'value' => 'days'];
    }

    public function rows(ScopedContext $ctx, array $filters): array
    {
        $range = self::range($filters);
        $rows = [];
        $people = [];
        foreach ($this->data->approvedLeave($ctx->employeeIds(), $range->from, $range->to) as $r) {
            $rows[$r['leave_type']] ??= ['leave_type' => $r['leave_type'], 'requests' => 0, 'days' => 0.0, 'employees' => 0];
            $rows[$r['leave_type']]['requests']++;
            $rows[$r['leave_type']]['days'] += $r['days'];
            $people[$r['leave_type']][$r['employee_id']] = true;
        }
        $rows = self::countPeople($rows, $people, 'employees');
        ksort($rows);

        return array_values($rows);
    }
}
