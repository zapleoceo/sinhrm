<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\Services;

use App\Modules\GoogleWorkspace\Contracts\CalendarClient;
use App\Modules\GoogleWorkspace\DTO\MeetingData;
use App\Modules\GoogleWorkspace\Enums\GoogleService;
use App\Modules\GoogleWorkspace\Exceptions\GoogleException;
use App\Modules\GoogleWorkspace\Support\GoogleApi;
use Illuminate\Support\Carbon;

final readonly class GoogleCalendarClient implements CalendarClient
{
    public const string EVENTS_URL = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';

    public function __construct(private GoogleApi $api) {}

    public function insertEvent(MeetingData $meeting, array $attendees, string $requestId): array
    {
        $body = [
            'summary' => $meeting->title,
            'start' => ['dateTime' => $meeting->start->toRfc3339String()],
            'end' => ['dateTime' => $meeting->end()->toRfc3339String()],
            'attendees' => array_map(static fn (string $email): array => ['email' => $email], $attendees),
        ];
        if ($meeting->notes !== null) {
            $body['description'] = $meeting->notes;
        }
        if ($meeting->location !== null) {
            $body['location'] = $meeting->location;
        }
        if ($meeting->isOnline()) {
            $body['conferenceData'] = ['createRequest' => [
                'requestId' => $requestId,
                'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
            ]];
        }

        $json = $this->api->post(GoogleService::Calendar, self::EVENTS_URL, [
            'conferenceDataVersion' => $meeting->isOnline() ? 1 : 0,
            'sendUpdates' => 'none',
        ], $body);
        if (! is_string($json['id'] ?? null)) {
            throw GoogleException::badResponse();
        }

        return [
            'event_id' => $json['id'],
            'html_link' => self::httpsOrNull($json['htmlLink'] ?? null),
            'meet_link' => self::httpsOrNull($json['hangoutLink'] ?? null) ?? self::videoEntryPoint($json),
        ];
    }

    public function insertAllDayEvent(string $title, Carbon $startsOn, Carbon $endsOn): string
    {
        $json = $this->api->post(GoogleService::Calendar, self::EVENTS_URL, ['sendUpdates' => 'none'], [
            'summary' => $title,
            'start' => ['date' => $startsOn->toDateString()],
            // Google's all-day end date is exclusive.
            'end' => ['date' => $endsOn->copy()->addDay()->toDateString()],
            'transparency' => 'transparent',
        ]);

        return is_string($json['id'] ?? null) ? $json['id'] : throw GoogleException::badResponse();
    }

    public function deleteEvent(string $eventId): void
    {
        $this->api->delete(GoogleService::Calendar, self::EVENTS_URL.'/'.rawurlencode($eventId).'?sendUpdates=none');
    }

    /** @param  array<string, mixed>  $json */
    private static function videoEntryPoint(array $json): ?string
    {
        $points = $json['conferenceData']['entryPoints'] ?? null;
        foreach (is_array($points) ? $points : [] as $point) {
            if (is_array($point) && ($point['entryPointType'] ?? null) === 'video') {
                return self::httpsOrNull($point['uri'] ?? null);
            }
        }

        return null;
    }

    private static function httpsOrNull(mixed $url): ?string
    {
        return is_string($url) && str_starts_with($url, 'https://') ? $url : null;
    }
}
