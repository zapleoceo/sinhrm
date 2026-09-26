<?php

declare(strict_types=1);

namespace App\Modules\Reports\Definitions;

use App\Modules\Reports\Contracts\ReportDataRepository;
use App\Modules\Reports\DTO\ScopedContext;
use App\Modules\Reports\Enums\ReportGroup;

/** Approved leave by type: requests and days (requests overlapping the range count whole). */
final class LeaveUsageReport extends AbstractReport
{
    public function __construct(private readonly ReportDataRepository $data) {}

    public function key(): string
    {
        return 'leave_usage';
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
        return [['key' => 'leave_type', 'type' => 'string'], ['key' => 'requests', 'type' => 'number'], ['key' => 'days', 'type' => 'number'], ['key' => 'employees', 'type' => 'number']];
    }

    public function chart(): array
    {
        return ['label' => 'leave_type', 'value' => 'days'];
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
        foreach ($this->data->approvedLeave($ctx->employeeIds(), $range->from, $range->to) as $r) {
            $rows[$r['leave_type']] ??= ['leave_type' => $r['leave_type'], 'requests' => 0, 'days' => 0.0, 'employees' => 0];
            $rows[$r['leave_type']]['requests']++;
            $rows[$r['leave_type']]['days'] += $r['days'];
            $people[$r['leave_type']][$r['employee_id']] = true;
        }
        foreach ($rows as $type => &$row) {
            $row['employees'] = count($people[$type] ?? []);
            $row['days'] = round($row['days'], 2);
        }
        unset($row);
        ksort($rows);

        return array_values($rows);
    }
}
