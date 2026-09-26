<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Enums;

enum LeaveUnit: string
{
    case Days = 'days';
    case Hours = 'hours';
}
