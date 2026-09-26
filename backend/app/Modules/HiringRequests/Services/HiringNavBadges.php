<?php

declare(strict_types=1);

namespace App\Modules\HiringRequests\Services;

use App\Models\User;
use App\Modules\Core\Contracts\NavBadgeProvider;

/**
 * "Заявки на найм" (/hiring-requests): requests whose current step waits for my decision (the page's inbox).
 * Who may decide is resolved in PHP per request, so this counts the very list the page gets.
 */
final readonly class HiringNavBadges implements NavBadgeProvider
{
    public function __construct(private HiringRequestService $requests) {}

    public function badges(User $user): array
    {
        return ['hiring_inbox' => $this->requests->inbox($user)->count()];
    }
}
