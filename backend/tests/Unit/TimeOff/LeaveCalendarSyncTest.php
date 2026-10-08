<?php

declare(strict_types=1);

namespace Tests\Unit\TimeOff;

use App\Modules\GoogleWorkspace\Contracts\CalendarClient;
use App\Modules\GoogleWorkspace\Contracts\GoogleConnections;
use App\Modules\GoogleWorkspace\DTO\ConnectionState;
use App\Modules\GoogleWorkspace\Enums\GoogleService;
use App\Modules\TimeOff\Models\LeaveRequest;
use App\Modules\TimeOff\Services\LeaveCalendarSync;
use Mockery\MockInterface;
use Tests\TestCase;

/** The leave calendar sync asks GoogleWorkspace's GoogleConnections contract first: Calendar not usable → no call. */
final class LeaveCalendarSyncTest extends TestCase
{
    public function test_nothing_is_sent_while_the_calendar_is_not_connected(): void
    {
        /** @var GoogleConnections&MockInterface $connections */
        $connections = $this->mock(GoogleConnections::class);
        $connections->expects('state')->with(GoogleService::Calendar)
            ->andReturn(new ConnectionState(GoogleService::Calendar, 'off', false, null, [], null, null));
        /** @var CalendarClient&MockInterface $calendar */
        $calendar = $this->mock(CalendarClient::class);
        $calendar->shouldNotReceive('insertAllDayEvent');

        $this->app->make(LeaveCalendarSync::class)->add(new LeaveRequest);
    }
}
