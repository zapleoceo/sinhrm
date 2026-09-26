<?php

declare(strict_types=1);

namespace App\Modules\Ai\Enums;

/** Lifecycle of an ai_requests row: pending (submitted, waiting) → done | failed. Terminal states never change. */
enum AiRequestStatus: string
{
    case Pending = 'pending';
    case Done = 'done';
    case Failed = 'failed';
}
