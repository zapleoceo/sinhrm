<?php

declare(strict_types=1);

namespace App\Modules\Reports\Definitions;

use App\Modules\Reports\DTO\ScopedContext;

/**
 * Missing timesheets: weeks not submitted/approved that still miss hours (expected − worked > 0; leave and holidays
 * are not missing), per employee, oldest first.
 */
final class TimeMissingReport extends AbstractTimeReport
{
    public function key(): string
    {
        return 'time_missing';
    }

    public function columns(): array
    {
        return [
            ['key' => 'employee', 'type' => 'string'],
            ['key' => 'department', 'type' => 'string'],
            ['key' => 'week_start', 'type' => 'date'],
            ['key' => 'status', 'type' => 'string'],
            ['key' => 'expected', 'type' => 'number'],
            ['key' => 'worked', 'type' => 'number'],
            ['key' => 'missing', 'type' => 'number'],
        ];
    }

    public function chart(): array
    {
        return ['label' => 'employee', 'value' => 'missing'];
    }

    public function rows(ScopedContext $ctx, array $filters): array
    {
        $rows = [];
        foreach ($this->weeks($ctx, $filters) as $w) {
            if (! $w['handed_in'] && $w['missing'] > 0) {
                $rows[] = [
                    'employee' => $w['employee'], 'department' => $w['department'] ?? '—', 'week_start' => $w['week_start'],
                    'status' => $w['status'], 'expected' => $w['expected'], 'worked' => $w['worked'], 'missing' => $w['missing'],
                ];
            }
        }
        usort($rows, static fn (array $a, array $b): int => [$a['week_start'], $a['employee']] <=> [$b['week_start'], $b['employee']]);

        return $rows;
    }
}
