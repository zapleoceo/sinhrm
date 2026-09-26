<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Enums;

/**
 * pending — not due yet, or executed and waiting for a person (its task); done / skipped — finished;
 * failed — the executor failed (retry button for admins, or skip).
 */
enum RunStepStatus: string
{
    case Pending = 'pending';
    case Done = 'done';
    case Skipped = 'skipped';
    case Failed = 'failed';

    public function isFinished(): bool
    {
        return $this === self::Done || $this === self::Skipped;
    }
}
