<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Support;

use App\Modules\Scripts\DTO\ApplicationActivity;
use App\Modules\Scripts\Enums\FollowupCondition;
use Illuminate\Support\Carbon;

/**
 * Pure follow-up conditions (no DB): returns the moment the follow-up became due, or null when it is not due now.
 * Stage names are not used on purpose (pipelines are configurable): "link not completed" = the application did not
 * move since our last outbound message.
 */
final class FollowupRules
{
    public static function dueAt(FollowupCondition $condition, int $delayDays, ApplicationActivity $a, Carbon $now): ?Carbon
    {
        $reference = match ($condition) {
            FollowupCondition::NoReply => $a->lastOutboundAt !== null && ! self::after($a->lastInboundAt, $a->lastOutboundAt)
                ? $a->lastOutboundAt
                : null,
            FollowupCondition::LinkNotCompleted => $a->lastOutboundAt !== null && ! self::after($a->lastStageChangeAt, $a->lastOutboundAt)
                ? $a->lastOutboundAt
                : null,
            FollowupCondition::GoneSilent => $a->lastTouchAt ?? $a->createdAt,
        };
        if ($reference === null) {
            return null;
        }
        $due = $reference->copy()->addDays($delayDays);

        return $due->lte($now) ? $due : null;
    }

    /** $moment exists and is later than $reference. */
    private static function after(?Carbon $moment, Carbon $reference): bool
    {
        return $moment !== null && $moment->gt($reference);
    }
}
