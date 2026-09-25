<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Enums;

enum VacancyStatus: string
{
    case Open = 'open';
    case Paused = 'paused';
    case Closed = 'closed';
}
