<?php

declare(strict_types=1);

namespace App\Modules\Documents\Enums;

/** draft → sent → signed | rejected; rejected can be edited and sent again; anything → archived. */
enum DocumentStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Signed = 'signed';
    case Rejected = 'rejected';
    case Archived = 'archived';

    /** Content and file may change only before the employee has it (or after they rejected it). */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Rejected;
    }
}
