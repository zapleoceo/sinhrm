<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Policies;

use App\Models\User;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Services\RecruitingScope;

/** An application follows its vacancy's branch. */
final readonly class ApplicationPolicy
{
    public function __construct(private RecruitingScope $scope) {}

    public function move(User $user, Application $application): bool
    {
        return $this->scope->canWrite($user) && $this->scope->canSeeVacancy($user, $application->vacancy);
    }
}
