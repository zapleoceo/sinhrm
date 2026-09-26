<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Enums;

/** running → completed (every step done or skipped) | cancelled (by an admin). */
enum RunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
