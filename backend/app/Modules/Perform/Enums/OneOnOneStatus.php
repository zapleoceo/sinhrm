<?php

declare(strict_types=1);

namespace App\Modules\Perform\Enums;

/** State of a 1:1 meeting. */
enum OneOnOneStatus: string
{
    case Scheduled = 'scheduled';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
