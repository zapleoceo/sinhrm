<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Executors;

use App\Modules\GoogleWorkspace\Contracts\CalendarClient;
use App\Modules\GoogleWorkspace\DTO\MeetingData;
use App\Modules\GoogleWorkspace\Enums\GoogleService;
use App\Modules\GoogleWorkspace\Exceptions\GoogleException;
use App\Modules\GoogleWorkspace\Services\GoogleConnectionStore;
use App\Modules\Workflows\Contracts\AssigneeDirectory;
use App\Modules\Workflows\Contracts\StepExecutor;
use App\Modules\Workflows\DTO\StepContext;
use App\Modules\Workflows\DTO\StepOutcome;
use App\Modules\Workflows\Enums\StepAction;
use Illuminate\Support\Str;

/**
 * add_calendar_event: an event in the connected Google Calendar on the step's due day at config "time"
 * (default 10:00, app timezone), with the employee's work e-mail and the assignee as attendees; Google sends no
 * invitations (sendUpdates=none, see CalendarClient). Calendar not connected → skipped "not_connected".
 */
final readonly class AddCalendarEventExecutor implements StepExecutor
{
    public const string DEFAULT_TIME = '10:00';

    public const int DEFAULT_MINUTES = 60;

    public function __construct(
        private GoogleConnectionStore $connections,
        private CalendarClient $calendar,
        private AssigneeDirectory $users,
    ) {}

    public function action(): StepAction
    {
        return StepAction::AddCalendarEvent;
    }

    public function configRules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'time' => ['nullable', 'date_format:H:i'],
            'duration_minutes' => ['nullable', 'integer', 'between:15,480'],
            'online' => ['sometimes', 'boolean'],
        ];
    }

    public function execute(StepContext $context): StepOutcome
    {
        if (! $this->connections->state(GoogleService::Calendar)->usable) {
            return StepOutcome::skipped('not_connected');
        }
        [$hour, $minute] = array_map('intval', explode(':', $context->step->string('time') ?? self::DEFAULT_TIME));
        $meeting = new MeetingData(
            title: $context->step->string('title') ?? $context->step->title,
            start: $context->runStep->due_at->copy()->setTime($hour, $minute),
            durationMinutes: $context->step->int('duration_minutes') ?? self::DEFAULT_MINUTES,
            type: $context->step->bool('online') ? MeetingData::TYPE_ONLINE : MeetingData::TYPE_BRANCH,
            inviteCandidate: false,
        );
        $attendees = [];
        if ($context->employee->work_email !== null) {
            $attendees[] = mb_strtolower($context->employee->work_email);
        }
        $assignee = $context->runStep->assignee_id === null ? null : $this->users->activeUser($context->runStep->assignee_id);
        if ($assignee !== null) {
            $attendees[] = mb_strtolower($assignee->email);
        }
        try {
            $event = $this->calendar->insertEvent($meeting, array_values(array_unique($attendees)), (string) Str::uuid());
        } catch (GoogleException $e) {
            return StepOutcome::failed($e->errorCode);
        }

        return StepOutcome::done(['event_id' => $event['event_id']]);
    }
}
