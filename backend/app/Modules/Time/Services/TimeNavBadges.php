<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Models\User;
use App\Modules\Core\Contracts\NavBadgeProvider;

/** "Погодження табелів" (/time/approvals): submitted weeks I may decide (0 for non-managers). */
final readonly class TimeNavBadges implements NavBadgeProvider
{
    public function __construct(private TimesheetService $timesheets) {}

    public function badges(User $user): array
    {
        return ['time_approvals' => $this->timesheets->approvalsCount($user)];
    }
}
