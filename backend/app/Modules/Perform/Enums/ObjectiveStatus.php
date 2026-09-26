<?php

declare(strict_types=1);

namespace App\Modules\Perform\Enums;

/** Objective lifecycle; progress is computed separately from key results. */
enum ObjectiveStatus: string
{
    case Active = 'active';
    case Achieved = 'achieved';
    case Missed = 'missed';
    case Cancelled = 'cancelled';
}
