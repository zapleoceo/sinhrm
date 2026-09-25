<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Enums;

/**
 * When a follow-up task becomes due (delay_days after the reference moment):
 * - no_reply — our last outbound message got no inbound answer;
 * - link_not_completed — since our last outbound message (e.g. the interview link) the application did not move;
 * - gone_silent — no real touch at all.
 */
enum FollowupCondition: string
{
    case NoReply = 'no_reply';
    case LinkNotCompleted = 'link_not_completed';
    case GoneSilent = 'gone_silent';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
