<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\DTO;

use App\Modules\TimeOff\Enums\LeaveRequestStatus;

final readonly class LeaveRequestFilter
{
    public function __construct(
        public ?int $employeeId = null,
        public ?LeaveRequestStatus $status = null,
        public ?int $leaveTypeId = null,
        public int $perPage = 50,
    ) {}
}
