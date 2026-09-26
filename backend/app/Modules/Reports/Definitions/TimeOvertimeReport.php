<?php

declare(strict_types=1);

namespace App\Modules\Reports\Definitions;

use App\Modules\Reports\DTO\ScopedContext;

/** Weeks with overtime (worked > expected by the schedule), per employee, newest week first. */
final class TimeOvertimeReport extends AbstractTimeReport
{
    public function key(): string
    {
        return 'time_overtime';
    }

    public function columns(): array
    {
        return [
            ['key' => 'employee', 'type' => 'string'],
            ['key' => 'week_start', 'type' => 'date'],
            ['key' => 'status', 'type' => 'string'],
            ['key' => 'expected', 'type' => 'number'],
            ['key' => 'worked', 'type' => 'number'],
            ['key' => 'overtime', 'type' => 'number'],
        ];
    }

    public function chart(): array
    {
        return ['label' => 'employee', 'value' => 'overtime'];
    }

    public function rows(ScopedContext $ctx, array $filters): array
    {
        $rows = [];
        foreach ($this->weeks($ctx, $filters) as $w) {
            if ($w['overtime'] > 0) {
                $rows[] = ['employee' => $w['employee'], 'week_start' => $w['week_start'], 'status' => $w['status'], 'expected' => $w['expected'], 'worked' => $w['worked'], 'overtime' => $w['overtime']];
            }
        }
        usort($rows, static fn (array $a, array $b): int => [$b['week_start'], $a['employee']] <=> [$a['week_start'], $b['employee']]);

        return $rows;
    }
}
