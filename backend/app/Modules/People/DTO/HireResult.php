<?php

declare(strict_types=1);

namespace App\Modules\People\DTO;

use App\Modules\People\Models\Employee;

final readonly class HireResult
{
    public function __construct(
        public Employee $employee,
        public bool $created,
    ) {}
}
