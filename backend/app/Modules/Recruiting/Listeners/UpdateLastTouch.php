<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Listeners;

use App\Modules\Recruiting\Contracts\ApplicationRepository;
use App\Modules\Recruiting\Events\TouchpointRecorded;

/**
 * Keeps applications.last_touch_at (staleness) in sync: a real touch (not "system") moves it forward for the
 * touchpoint's application, or for every active application of the candidate when no application is given.
 */
final readonly class UpdateLastTouch
{
    public function __construct(private ApplicationRepository $applications) {}

    public function handle(TouchpointRecorded $event): void
    {
        $touch = $event->touchpoint;
        if (! $touch->channel->isTouch() || $touch->candidate_id === null) {
            return;
        }
        $this->applications->bumpLastTouch($touch->candidate_id, $touch->application_id, $touch->occurred_at);
    }
}
