<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Services;

use App\Models\User;
use App\Modules\Core\Contracts\NavBadgeProvider;

/** "Опитування" (/pulse): open survey waves addressed to me that I have not answered yet. */
final readonly class PulseNavBadges implements NavBadgeProvider
{
    public function __construct(private ResponseService $responses) {}

    public function badges(User $user): array
    {
        return ['surveys' => $this->responses->countPending($user)];
    }
}
