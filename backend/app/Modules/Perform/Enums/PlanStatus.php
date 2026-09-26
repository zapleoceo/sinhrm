<?php

declare(strict_types=1);

namespace App\Modules\Perform\Enums;

/** Development plan state. */
enum PlanStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
