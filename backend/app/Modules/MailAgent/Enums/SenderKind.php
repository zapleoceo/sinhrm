<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Enums;

/** What a sender rule says about the mail of a sender. */
enum SenderKind: string
{
    /** A job site notification about a new application: parsed into a candidate. */
    case JobBoard = 'job_board';
    /** A candidate writing directly: a touchpoint on their card (or the inbox). */
    case Candidate = 'candidate';
    case Colleague = 'colleague';
    case Newsletter = 'newsletter';
    case Ignore = 'ignore';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $k): string => $k->value, self::cases());
    }

    /** Mail of this kind is skipped without storing anything but the log line. */
    public function isSkipped(): bool
    {
        return in_array($this, [self::Colleague, self::Newsletter, self::Ignore], true);
    }
}
