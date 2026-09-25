<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Enums;

/** What the sync did with one message (mail_messages.outcome, counters of a run). */
enum MailOutcome: string
{
    /** Job-board mail → candidate created/matched and applied to the vacancy. */
    case Application = 'application';
    /** Stored as an e-mail touchpoint on a candidate. */
    case Touchpoint = 'touchpoint';
    /** Stored as an unmatched e-mail touchpoint (Inbox). */
    case Inbox = 'inbox';
    /** ignore / newsletter / colleague, sent by us, or no sender. */
    case Skipped = 'skipped';
    /** No rule and not a candidate: sender queued in unknown_senders. */
    case Unknown = 'unknown';
    /** Job-board mail without a usable contact. */
    case ParseFailed = 'parse_failed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $o): string => $o->value, self::cases());
    }
}
