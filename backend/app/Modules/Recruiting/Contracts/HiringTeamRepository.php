<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Contracts;

use App\Modules\Recruiting\Models\Application;

/** Contextual recruiting roles: hiring manager (vacancies.hiring_manager_id) and interviewers (application_interviewers). */
interface HiringTeamRepository
{
    /** @return list<int> vacancies where the user is the hiring manager */
    public function managedVacancyIds(int $userId): array;

    /** @return list<int> applications where the user is an interviewer */
    public function interviewApplicationIds(int $userId): array;

    /**
     * Replaces the interviewers of the application.
     *
     * @param  list<int>  $userIds
     */
    public function syncInterviewers(Application $application, array $userIds): Application;
}
