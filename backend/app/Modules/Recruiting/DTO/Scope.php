<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\DTO;

/**
 * What a user may see in Recruiting. branchIds null = everything (HR staff); otherwise vacancies of these branches,
 * their applications and candidates, plus candidates the user owns/created and inbox messages they authored.
 * On top of that come contextual roles: vacancies where the user is the hiring manager (with all their applications)
 * and single applications where the user is an interviewer.
 */
final readonly class Scope
{
    /**
     * @param  list<int>|null  $branchIds
     * @param  list<int>  $managedVacancyIds
     * @param  list<int>  $interviewApplicationIds
     */
    public function __construct(
        public int $userId,
        public ?array $branchIds,
        public array $managedVacancyIds = [],
        public array $interviewApplicationIds = [],
    ) {}

    public function isUnrestricted(): bool
    {
        return $this->branchIds === null;
    }

    public function allowsBranch(int $branchId): bool
    {
        return $this->branchIds === null || in_array($branchId, $this->branchIds, true);
    }

    public function managesVacancy(int $vacancyId): bool
    {
        return in_array($vacancyId, $this->managedVacancyIds, true);
    }

    public function allowsVacancy(int $vacancyId, int $branchId): bool
    {
        return $this->allowsBranch($branchId) || $this->managesVacancy($vacancyId);
    }

    public function hasContextualAccess(): bool
    {
        return $this->managedVacancyIds !== [] || $this->interviewApplicationIds !== [];
    }
}
