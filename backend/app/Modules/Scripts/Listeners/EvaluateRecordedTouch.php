<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Listeners;

use App\Modules\Recruiting\Events\TouchpointRecorded;
use App\Modules\Scripts\Enums\ScriptChannel;
use App\Modules\Scripts\Jobs\EvaluateTouchpoint;

/**
 * A call with a transcript or a long outbound chat message was recorded (manual log, integration, inbox link) →
 * evaluate it after the response. Cheap filter here so other touches never schedule anything.
 */
final class EvaluateRecordedTouch
{
    public function handle(TouchpointRecorded $event): void
    {
        $touch = $event->touchpoint;
        if ($touch->candidate_id === null || ScriptChannel::forTouch($touch->channel, $touch->direction, $touch->body) === null) {
            return;
        }
        EvaluateTouchpoint::dispatchAfterResponse($touch->id);
    }
}
