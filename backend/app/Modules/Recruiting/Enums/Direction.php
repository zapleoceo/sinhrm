<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Enums;

/** in = from the candidate, out = from us. */
enum Direction: string
{
    case In = 'in';
    case Out = 'out';
}
