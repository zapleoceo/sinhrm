<?php

declare(strict_types=1);

namespace App\Modules\Reports\Definitions;

use App\Modules\Reports\DTO\ScopedContext;

/** Hours by employee for the period: expected (schedule − leave − holidays), worked, overtime, missing, absence. */
final class TimeByEmployeeReport extends AbstractTimeReport
{
    public function key(): string
    {
        return 'time_by_employee';
    }

    public function columns(): array
    {
        return [
            ['key' => 'employee', 'type' => 'string'],
            ['key' => 'department', 'type' => 'string'],
            ['key' => 'expected', 'type' => 'number', 'total' => 'sum'],
            ['key' => 'worked', 'type' => 'number', 'total' => 'sum'],
            ['key' => 'overtime', 'type' => 'number', 'total' => 'sum'],
            ['key' => 'missing', 'type' => 'number', 'total' => 'sum'],
            ['key' => 'absence', 'type' => 'number', 'total' => 'sum'],
        ];
    }

    public function chart(): array
    {
        return ['label' => 'employee', 'value' => 'worked'];
    }

    public function rows(ScopedContext $ctx, array $filters): array
    {
        return self::sum($this->weeks($ctx, $filters), static fn (array $w): array => ['employee' => $w['employee'], 'department' => $w['department'] ?? '—']);
    }
}
