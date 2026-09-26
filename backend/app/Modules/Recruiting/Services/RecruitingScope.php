<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Services;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Contracts\AccessibleBranches;
use App\Modules\Recruiting\Contracts\CandidateRepository;
use App\Modules\Recruiting\Contracts\HiringTeamRepository;
use App\Modules\Recruiting\Contracts\TouchpointRepository;
use App\Modules\Recruiting\DTO\Scope;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\Touchpoint;
use App\Modules\Recruiting\Models\Vacancy;

/**
 * Who sees and edits what in Recruiting (used by the policies and the services):
 * - superadmin/admin/hr_manager — everything (hr_manager read-only);
 * - recruiter — vacancies of their branches, their applications and candidates (+ candidates they own/created),
 *   may edit them;
 * - viewer — the same visibility, read-only;
 * - employee — nothing by role;
 * - any role, contextually: the hiring manager of a vacancy sees and works it (edit, move applications, assign
 *   interviewers); an interviewer sees only the candidate of the application they are assigned to;
 * - blocked — nothing.
 */
final readonly class RecruitingScope
{
    private const array MANAGERS = [UserRole::Superadmin, UserRole::Admin];

    public function __construct(
        private AccessibleBranches $branches,
        private CandidateRepository $candidates,
        private TouchpointRepository $touchpoints,
        private HiringTeamRepository $team,
    ) {}

    public function for(User $user): Scope
    {
        if (! $user->isActive()) {
            return new Scope($user->id, []);
        }
        $branchIds = $this->branches->for($user);
        if ($branchIds !== [] && ! $this->hasAny($user, UserRole::recruitingReaders())) {
            $branchIds = []; // employee: branches do not open Recruiting, only contextual roles do
        }

        return new Scope($user->id, $branchIds, $this->team->managedVacancyIds($user->id), $this->team->interviewApplicationIds($user->id));
    }

    public function canWrite(User $user): bool
    {
        return $this->hasAny($user, UserRole::recruitingWriters());
    }

    /** Pipelines and the reject reasons dictionary. */
    public function canManage(User $user): bool
    {
        return $this->hasAny($user, self::MANAGERS);
    }

    public function isHiringManager(User $user, Vacancy $vacancy): bool
    {
        return $user->isActive() && $vacancy->hiring_manager_id === $user->id;
    }

    /** Edit the vacancy, move its applications, assign interviewers: writers in scope or the vacancy's hiring manager. */
    public function canWorkVacancy(User $user, Vacancy $vacancy): bool
    {
        return ($this->canWrite($user) && $this->canSeeVacancy($user, $vacancy)) || $this->isHiringManager($user, $vacancy);
    }

    public function canSeeVacancy(User $user, Vacancy $vacancy): bool
    {
        return $user->isActive() && $this->for($user)->allowsVacancy($vacancy->id, $vacancy->branch_id);
    }

    public function canSeeCandidate(User $user, Candidate $candidate): bool
    {
        return $user->isActive() && $this->candidates->isVisible($this->for($user), $candidate->id);
    }

    public function canSeeInboxItem(User $user, Touchpoint $touchpoint): bool
    {
        return $user->isActive() && $this->touchpoints->isInboxVisible($this->for($user), $touchpoint);
    }

    /** @param  list<UserRole>  $roles */
    private function hasAny(User $user, array $roles): bool
    {
        return $user->isActive() && $user->hasAnyRole(UserRole::valuesOf($roles));
    }
}
