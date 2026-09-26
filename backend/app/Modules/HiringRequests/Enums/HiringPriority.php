<?php

declare(strict_types=1);

namespace App\Modules\HiringRequests\Enums;

enum HiringPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';
}
