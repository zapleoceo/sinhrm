<?php

declare(strict_types=1);

namespace App\Modules\Reports\Datasets;

use App\Modules\Reports\Contracts\Dataset;
use App\Modules\Reports\DTO\ScopedContext;

/** Employees in the People scope (admin: all; manager: subtree + self). Birth date and personal contacts are PII. */
final class EmployeesDataset implements Dataset
{
    public function key(): string
    {
        return 'employees';
    }

    public function available(ScopedContext $ctx): bool
    {
        return $ctx->seesTeam();
    }

    public function columns(): array
    {
        return [
            'id' => ['expr' => 'e.id', 'type' => self::NUMBER],
            'full_name' => ['expr' => 'e.full_name', 'type' => self::STRING],
            'status' => ['expr' => 'e.status', 'type' => self::STRING],
            'employment_type' => ['expr' => 'e.employment_type', 'type' => self::STRING],
            'branch' => ['expr' => 'b.name', 'type' => self::STRING],
            'department' => ['expr' => 'd.name', 'type' => self::STRING],
            'position' => ['expr' => 'p.name', 'type' => self::STRING],
            'manager' => ['expr' => 'm.full_name', 'type' => self::STRING],
            'work_email' => ['expr' => 'e.work_email', 'type' => self::STRING],
            'hired_at' => ['expr' => 'e.hired_at', 'type' => self::DATE],
            'fired_at' => ['expr' => 'e.fired_at', 'type' => self::DATE],
            'birth_date' => ['expr' => 'e.birth_date', 'type' => self::DATE, 'pii' => true],
            'personal_email' => ['expr' => 'e.personal_email', 'type' => self::STRING, 'pii' => true],
            // Work phone: directory tier in People (not PII).
            'phone' => ['expr' => 'e.phone', 'type' => self::STRING],
        ];
    }
}
