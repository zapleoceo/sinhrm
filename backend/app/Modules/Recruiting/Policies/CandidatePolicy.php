<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Policies;

use App\Models\User;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Services\RecruitingScope;

/** Candidates in scope (applications on vacancies of the user's branches, or owned/created by the user). */
final readonly class CandidatePolicy
{
    public function __construct(private RecruitingScope $scope) {}

    public function view(User $user, Candidate $candidate): bool
    {
        return $this->scope->canSeeCandidate($user, $candidate);
    }

    public function create(User $user): bool
    {
        return $this->scope->canWrite($user);
    }

    /** Edit fields and log touches. */
    public function update(User $user, Candidate $candidate): bool
    {
        return $this->scope->canWrite($user) && $this->scope->canSeeCandidate($user, $candidate);
    }
}
