<?php

declare(strict_types=1);

namespace App\Modules\Desk\Enums;

/** Lifecycle of a helpdesk case. Resolved and closed stop the SLA clock. */
enum CaseStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Waiting = 'waiting';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function isOpen(): bool
    {
        return $this !== self::Resolved && $this !== self::Closed;
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return [self::New->value, self::InProgress->value, self::Waiting->value];
    }
}
