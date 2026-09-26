<?php

declare(strict_types=1);

namespace App\Modules\HiringRequests\Enums;

/** One step of a request's route: waiting (not reached) → pending (current) → approved | rejected; or skipped. */
enum ApprovalStatus: string
{
    case Waiting = 'waiting';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Skipped = 'skipped';
}
