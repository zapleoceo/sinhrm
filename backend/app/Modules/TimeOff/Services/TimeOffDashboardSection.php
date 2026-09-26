<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Services;

use App\Models\User;
use App\Modules\Overview\Contracts\DashboardSection;
use App\Modules\People\Services\PeopleScope;
use App\Modules\TimeOff\Models\LeaveRequest;
use Illuminate\Support\Carbon;

/** Home page block "timeoff": {out_today: [...], my_approvals: {count, items[≤5]}} in the user's scope. */
final readonly class TimeOffDashboardSection implements DashboardSection
{
    public const int APPROVALS_LIST = 5;

    public function __construct(
        private PeopleScope $scope,
        private CalendarService $calendar,
        private LeaveRequestService $requests,
    ) {}

    public function key(): string
    {
        return 'timeoff';
    }

    public function data(User $user, Carbon $now): array
    {
        $ctx = $this->scope->for($user);
        $approvals = $this->requests->approvals($ctx);

        return [
            'out_today' => $this->calendar->outOn($ctx, $now->copy()->startOfDay()),
            'my_approvals' => [
                'count' => $approvals->count(),
                'items' => array_values($approvals->take(self::APPROVALS_LIST)->map(static fn (LeaveRequest $r): array => [
                    'id' => $r->id,
                    'employee' => ['id' => $r->employee->id, 'full_name' => $r->employee->full_name],
                    'leave_type' => ['id' => $r->leaveType->id, 'name' => $r->leaveType->name, 'color' => $r->leaveType->color],
                    'starts_on' => $r->starts_on->toDateString(),
                    'ends_on' => $r->ends_on->toDateString(),
                    'days' => $r->daysValue(),
                ])->all()),
            ],
        ];
    }
}
