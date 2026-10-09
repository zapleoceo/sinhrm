<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Services;

use App\Modules\GoogleWorkspace\Contracts\CalendarClient;
use App\Modules\GoogleWorkspace\Contracts\GoogleConnections;
use App\Modules\GoogleWorkspace\Enums\GoogleService;
use App\Modules\GoogleWorkspace\Exceptions\GoogleException;
use App\Modules\TimeOff\Contracts\LeaveRequestRepository;
use App\Modules\TimeOff\Models\LeaveRequest;
use Psr\Log\LoggerInterface;

/**
 * Approved request → all-day event "Відпустка: <name>" in the connected company Google Calendar; cancelled → the
 * event is deleted. Skipped when Calendar is not connected; Google errors are logged and never break the request.
 */
final readonly class LeaveCalendarSync
{
    public function __construct(
        private CalendarClient $calendar,
        private GoogleConnections $connections,
        private LeaveRequestRepository $requests,
        private LoggerInterface $log,
    ) {}

    public function add(LeaveRequest $request): void
    {
        if ($request->calendar_event_id !== null || ! $this->connected()) {
            return;
        }
        try {
            $id = $this->calendar->insertAllDayEvent('Відпустка: '.$request->employee->full_name, $request->starts_on, $request->ends_on);
            $this->requests->setCalendarEvent($request, $id);
        } catch (GoogleException $e) {
            $this->log->warning('timeoff.calendar_failed', ['id' => $request->id, 'code' => $e->errorCode]);
        }
    }

    public function remove(LeaveRequest $request): void
    {
        if ($request->calendar_event_id === null || ! $this->connected()) {
            return;
        }
        try {
            $this->calendar->deleteEvent($request->calendar_event_id);
            $this->requests->setCalendarEvent($request, null);
        } catch (GoogleException $e) {
            $this->log->warning('timeoff.calendar_failed', ['id' => $request->id, 'code' => $e->errorCode]);
        }
    }

    private function connected(): bool
    {
        return $this->connections->state(GoogleService::Calendar)->usable;
    }
}
