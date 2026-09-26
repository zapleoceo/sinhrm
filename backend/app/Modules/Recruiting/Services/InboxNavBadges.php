<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Services;

use App\Models\User;
use App\Modules\Core\Contracts\NavBadgeProvider;

/** "Вхідні" (/inbox): messages not linked to a candidate yet, in the user's recruiting scope. */
final readonly class InboxNavBadges implements NavBadgeProvider
{
    public function __construct(private InboxService $inbox) {}

    public function badges(User $user): array
    {
        return ['inbox' => $this->inbox->list($user, 1)->total()];
    }
}
