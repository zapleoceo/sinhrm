<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Enums;

/** Wave lifecycle: scheduled until starts_at, open until ends_at (or closed by hand), then closed. */
enum WaveStatus: string
{
    case Scheduled = 'scheduled';
    case Open = 'open';
    case Closed = 'closed';
}
