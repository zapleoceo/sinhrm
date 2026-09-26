<?php

declare(strict_types=1);

namespace App\Modules\Time\Enums;

/** draft (being filled) → submitted → approved | rejected (back to editing; saving turns it into draft again). */
enum TimesheetStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Rejected;
    }

    /** Counted as "filled" by reminders and the missing-timesheets report. */
    public function isHandedIn(): bool
    {
        return $this === self::Submitted || $this === self::Approved;
    }
}
