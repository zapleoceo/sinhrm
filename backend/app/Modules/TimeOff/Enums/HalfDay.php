<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Enums;

enum HalfDay: string
{
    case None = 'none';
    case Start = 'start';
    case End = 'end';
}
