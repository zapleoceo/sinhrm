<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Enums;

enum TaskType: string
{
    case Followup = 'followup';
    case Manual = 'manual';
}
