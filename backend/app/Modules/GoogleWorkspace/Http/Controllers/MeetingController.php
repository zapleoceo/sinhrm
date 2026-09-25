<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\Http\Controllers;

use App\Models\User;
use App\Modules\GoogleWorkspace\Exceptions\GoogleException;
use App\Modules\GoogleWorkspace\Http\Requests\ScheduleMeetingRequest;
use App\Modules\GoogleWorkspace\Services\MeetingService;
use App\Modules\Recruiting\Http\Resources\TouchpointResource;
use App\Modules\Recruiting\Models\Candidate;
use Illuminate\Http\JsonResponse;

final readonly class MeetingController
{
    public function __construct(private MeetingService $meetings) {}

    /** 201 {data: {event_id, meet_link, html_link, touchpoint}}; calendar not connected → 422. */
    public function store(ScheduleMeetingRequest $request, Candidate $candidate): JsonResponse
    {
        if (! $this->meetings->calendarConnected()) {
            throw GoogleException::notConnected('calendar');
        }
        $actor = $request->user();
        assert($actor instanceof User);
        $result = $this->meetings->schedule($actor, $candidate, $request->meeting());

        return new JsonResponse(['data' => [
            'event_id' => $result['event_id'],
            'meet_link' => $result['meet_link'],
            'html_link' => $result['html_link'],
            'touchpoint' => (new TouchpointResource($result['touchpoint']))->resolve($request),
        ]], 201);
    }
}
