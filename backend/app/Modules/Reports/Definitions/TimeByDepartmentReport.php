<?php

declare(strict_types=1);

namespace App\Modules\Reports\Definitions;

use App\Modules\Reports\DTO\ScopedContext;

/** Hours by department for the period (the same week figures as time_by_employee, summed). */
final class TimeByDepartmentReport extends AbstractTimeReport
{
    public function key(): string
    {
        return 'time_by_department';
    }

    public function columns(): array
    {
        return [
            ['key' => 'department', 'type' => 'string'],
            ['key' => 'expected', 'type' => 'number'],
            ['key' => 'worked', 'type' => 'number'],
            ['key' => 'overtime', 'type' => 'number'],
            ['key' => 'missing', 'type' => 'number'],
            ['key' => 'absence', 'type' => 'number'],
        ];
    }

    public function chart(): array
    {
        return ['label' => 'department', 'value' => 'worked'];
    }

    public function rows(ScopedContext $ctx, array $filters): array
    {
        return self::sum($this->weeks($ctx, $filters), static fn (array $w): array => ['department' => $w['department'] ?? '—']);
    }
}
