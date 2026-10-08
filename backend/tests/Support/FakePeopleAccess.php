<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\People\Contracts\PeopleAccess;
use App\Modules\People\DTO\PeopleContext;
use App\Modules\People\Models\Employee;

/**
 * People\Contracts\PeopleAccess double for unit tests of other modules: a fixed answer, no database.
 * Bind with $this->app->instance(PeopleAccess::class, new FakePeopleAccess(...)).
 */
final class FakePeopleAccess implements PeopleAccess
{
    /** @param  list<int>  $subtree */
    public function __construct(
        public bool $admin = false,
        public ?Employee $employee = null,
        public array $subtree = [],
    ) {}

    public function isAdmin(User $user): bool
    {
        return $this->admin;
    }

    public function employeeOf(User $user): ?Employee
    {
        return $this->employee;
    }

    public function for(User $user): PeopleContext
    {
        return new PeopleContext($user->id, $this->admin, $this->employee?->id, $this->subtree);
    }
}
