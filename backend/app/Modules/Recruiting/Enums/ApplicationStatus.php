<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Enums;

/**
 * Outcome of an application. Derived from its stage: a terminal "hire" stage → hired, a terminal "closed" stage →
 * rejected, anything else → active (moving back from a terminal stage re-opens the application).
 */
enum ApplicationStatus: string
{
    case Active = 'active';
    case Hired = 'hired';
    case Rejected = 'rejected';
}
