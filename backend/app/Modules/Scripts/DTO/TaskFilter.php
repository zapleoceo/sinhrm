<?php

declare(strict_types=1);

namespace App\Modules\Scripts\DTO;

use App\Modules\Scripts\Enums\TaskDue;
use App\Modules\Scripts\Enums\TaskSource;

/** GET /api/tasks filters. Open tasks only unless $withDone. */
final readonly class TaskFilter
{
    public function __construct(
        public bool $mine = false,
        public ?TaskDue $due = null,
        public ?int $candidateId = null,
        public bool $withDone = false,
        public ?TaskSource $source = null,
        public ?int $employeeId = null,
    ) {}
}
