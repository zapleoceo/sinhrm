<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\Contracts;

use App\Modules\GoogleWorkspace\DTO\MeetingData;
use App\Modules\GoogleWorkspace\Exceptions\GoogleException;

/** Google Calendar v3 on the connected account's primary calendar. */
interface CalendarClient
{
    /**
     * events.insert; online → conferenceData createRequest (Meet), conferenceDataVersion=1. sendUpdates=none:
     * Google sends no invitations, the recruiter copies the link.
     *
     * @param  list<string>  $attendees  e-mails
     * @return array{event_id: string, html_link: string|null, meet_link: string|null}
     *
     * @throws GoogleException
     */
    public function insertEvent(MeetingData $meeting, array $attendees, string $requestId): array;
}
