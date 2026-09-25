<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Policies;

use App\Models\User;
use App\Modules\Recruiting\Models\Touchpoint;
use App\Modules\Recruiting\Services\RecruitingScope;

/** Inbox messages: authored by the user or received on a line of their branches (admins: all). */
final readonly class TouchpointPolicy
{
    public function __construct(private RecruitingScope $scope) {}

    /** Link to a candidate / create a candidate from the message. */
    public function resolve(User $user, Touchpoint $touchpoint): bool
    {
        return $this->scope->canWrite($user) && $this->scope->canSeeInboxItem($user, $touchpoint);
    }
}
