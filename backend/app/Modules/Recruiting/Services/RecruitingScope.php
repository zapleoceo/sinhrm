<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Services;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Contracts\AccessibleBranches;
use App\Modules\Recruiting\Contracts\CandidateRepository;
use App\Modules\Recruiting\Contracts\TouchpointRepository;
use App\Modules\Recruiting\DTO\Scope;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\Touchpoint;
use App\Modules\Recruiting\Models\Vacancy;

/**
 * Who sees and edits what in Recruiting (used by the policies and the services):
 * - superadmin/admin — everything;
 * - recruiter — vacancies of their branches, their applications and candidates (+ candidates they own/created),
 *   may edit them;
 * - viewer — the same visibility, read-only;
 * - blocked — nothing.
 */
final readonly class RecruitingScope
{
    private const array WRITERS = [UserRole::Superadmin, UserRole::Admin, UserRole::Recruiter];

    private const array MANAGERS = [UserRole::Superadmin, UserRole::Admin];

    public function __construct(
        private AccessibleBranches $branches,
        private CandidateRepository $candidates,
        private TouchpointRepository $touchpoints,
    ) {}

    public function for(User $user): Scope
    {
        return new Scope($user->id, $this->branches->for($user));
    }

    public function canWrite(User $user): bool
    {
        return $this->hasAny($user, self::WRITERS);
    }

    /** Pipelines and the reject reasons dictionary. */
    public function canManage(User $user): bool
    {
        return $this->hasAny($user, self::MANAGERS);
    }

    public function canSeeVacancy(User $user, Vacancy $vacancy): bool
    {
        return $user->isActive() && $this->for($user)->allowsBranch($vacancy->branch_id);
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
        return $user->isActive() && $user->hasAnyRole(array_map(static fn (UserRole $r): string => $r->value, $roles));
    }
}
