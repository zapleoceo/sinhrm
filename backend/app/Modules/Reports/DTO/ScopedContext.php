<?php

declare(strict_types=1);

namespace App\Modules\Reports\DTO;

use App\Models\User;
use App\Modules\People\DTO\PeopleContext;
use App\Modules\Recruiting\DTO\Scope;
use Illuminate\Support\Carbon;

/**
 * Everything a report may see for one user, computed once per request from the existing access models:
 * People (PeopleScope: admin = all, manager = subtree + self, others = self) and Recruiting (AccessibleBranches:
 * admin = all branches, others = their branches). Reports never widen these scopes.
 */
final readonly class ScopedContext
{
    public function __construct(
        public User $user,
        public PeopleContext $people,
        public Scope $recruiting,
        public Carbon $now,
    ) {}

    public function isAdmin(): bool
    {
        return $this->people->admin;
    }

    /** Admin or a manager of at least one person (HR and performance reports). */
    public function seesTeam(): bool
    {
        return $this->people->admin || $this->people->isManager();
    }

    /** @return list<int>|null employees whose data is in scope; null = everyone (admin) */
    public function employeeIds(): ?array
    {
        return $this->people->visibleIds();
    }
}
