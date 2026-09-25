<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\Services;

use App\Models\User;
use App\Modules\GoogleWorkspace\Contracts\CalendarClient;
use App\Modules\GoogleWorkspace\DTO\MeetingData;
use App\Modules\GoogleWorkspace\Enums\GoogleService;
use App\Modules\Recruiting\DTO\TouchpointData;
use App\Modules\Recruiting\Enums\Channel;
use App\Modules\Recruiting\Enums\Direction;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\Touchpoint;
use App\Modules\Recruiting\Services\TouchpointService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

/**
 * "Schedule a meeting" from the candidate card: an event in the connected Google Calendar (with a Meet link when
 * online) + a "meeting" touchpoint on the candidate's timeline (meta: event_id, meet_link, html_link, start, end,
 * meeting_type). The candidate is added as an attendee only when asked; Google sends nothing (sendUpdates=none).
 */
final readonly class MeetingService
{
    public function __construct(
        private CalendarClient $calendar,
        private GoogleConnectionStore $connections,
        private TouchpointService $touchpoints,
        private LoggerInterface $log,
    ) {}

    public function calendarConnected(): bool
    {
        return $this->connections->state(GoogleService::Calendar)->usable;
    }

    /** @return array{event_id: string, html_link: string|null, meet_link: string|null, touchpoint: Touchpoint} */
    public function schedule(User $actor, Candidate $candidate, MeetingData $meeting): array
    {
        $attendees = [mb_strtolower($actor->email)];
        if ($meeting->inviteCandidate && $candidate->email !== null) {
            $attendees[] = $candidate->email;
        }
        $event = $this->calendar->insertEvent($meeting, array_values(array_unique($attendees)), (string) Str::uuid());

        $touchpoint = $this->touchpoints->log($actor, $candidate, new TouchpointData(
            channel: Channel::Meeting,
            direction: Direction::Out,
            body: $meeting->notes,
            occurredAt: Carbon::now(),
            meta: array_filter([
                'event_id' => $event['event_id'],
                'meet_link' => $event['meet_link'],
                'html_link' => $event['html_link'],
                'start' => $meeting->start->toIso8601String(),
                'end' => $meeting->end()->toIso8601String(),
                'meeting_type' => $meeting->type,
                'title' => $meeting->title,
            ], static fn (mixed $v): bool => $v !== null),
        ));
        $this->log->info('google.meeting_scheduled', ['candidate' => $candidate->id, 'by' => $actor->id, 'type' => $meeting->type]);

        return $event + ['touchpoint' => $touchpoint];
    }
}
