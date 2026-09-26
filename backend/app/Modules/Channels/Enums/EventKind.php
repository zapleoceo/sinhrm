<?php

declare(strict_types=1);

namespace App\Modules\Channels\Enums;

/** message / call → a touchpoint; status (delivery receipts, connection changes) → counted, not stored. */
enum EventKind: string
{
    case Message = 'message';
    case Call = 'call';
    case Status = 'status';

    public function createsTouch(): bool
    {
        return $this !== self::Status;
    }
}
