<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Policies;

use App\Models\User;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Services\RecruitingScope;

/** An application follows its vacancy (branch scope or the vacancy's hiring manager). */
final readonly class ApplicationPolicy
{
    public function __construct(private RecruitingScope $scope) {}

    public function move(User $user, Application $application): bool
    {
        return $this->scope->canWorkVacancy($user, $application->vacancy);
    }

    /** Assign interviewers: whoever may work the vacancy (writers in scope, the hiring manager). */
    public function assignInterviewers(User $user, Application $application): bool
    {
        return $this->scope->canWorkVacancy($user, $application->vacancy);
    }
}
