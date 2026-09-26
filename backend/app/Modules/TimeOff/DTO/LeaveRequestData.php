<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\DTO;

use App\Modules\TimeOff\Enums\HalfDay;
use Illuminate\Support\Carbon;

final readonly class LeaveRequestData
{
    public function __construct(
        public int $leaveTypeId,
        public Carbon $startsOn,
        public Carbon $endsOn,
        public HalfDay $halfDay = HalfDay::None,
        public ?string $comment = null,
        public bool $overrideBalance = false,
    ) {}
}
