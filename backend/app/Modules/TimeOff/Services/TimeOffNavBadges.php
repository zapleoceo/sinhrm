<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Services;

use App\Models\User;
use App\Modules\Core\Contracts\NavBadgeProvider;
use App\Modules\People\Services\PeopleScope;

/** "Погодження відсутностей" (/timeoff/approvals): pending leave requests I may decide (0 for non-managers). */
final readonly class TimeOffNavBadges implements NavBadgeProvider
{
    public function __construct(private LeaveRequestService $requests, private PeopleScope $scope) {}

    public function badges(User $user): array
    {
        return ['timeoff_approvals' => $this->requests->approvalsCount($this->scope->for($user))];
    }
}
