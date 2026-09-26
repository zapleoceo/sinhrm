<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Policies;

use App\Models\User;
use App\Modules\Recruiting\Models\Vacancy;
use App\Modules\Recruiting\Services\RecruitingScope;

/** Vacancies of the user's branches (HR staff: all) or where the user is the hiring manager; viewers read-only. */
final readonly class VacancyPolicy
{
    public function __construct(private RecruitingScope $scope) {}

    public function view(User $user, Vacancy $vacancy): bool
    {
        return $this->scope->canSeeVacancy($user, $vacancy);
    }

    public function create(User $user): bool
    {
        return $this->scope->canWrite($user);
    }

    /** Edit the vacancy and add candidates to it. */
    public function update(User $user, Vacancy $vacancy): bool
    {
        return $this->scope->canWorkVacancy($user, $vacancy);
    }
}
