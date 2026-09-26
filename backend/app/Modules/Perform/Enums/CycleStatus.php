<?php

declare(strict_types=1);

namespace App\Modules\Perform\Enums;

/** Review cycle lifecycle: assignments are created on activation, answers accepted while active. */
enum CycleStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Closed = 'closed';
}
