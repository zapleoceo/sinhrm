<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\People\Models\Employee;

/**
 * Builders for People / TimeOff tests. Synthetic data only.
 * The standard org: head ← lead ← worker (lead reports to head, worker to lead), plus an unrelated "other"
 * with a peer "peer" under the same manager as the worker (lead). Every one has a login with role recruiter.
 */
trait PeopleFixtures
{
    /** @param  array<string, mixed>  $attributes */
    protected function employee(array $attributes = [], ?User $user = null): Employee
    {
        return Employee::factory()->create(['user_id' => $user?->id] + $attributes);
    }

    protected function login(UserRole $role = UserRole::Recruiter): User
    {
        return User::factory()->withRole($role)->create();
    }

    /** @return array{head: Employee, lead: Employee, worker: Employee, peer: Employee, other: Employee} */
    protected function org(): array
    {
        $head = $this->employee(['full_name' => 'Head Person'], $this->login());
        $lead = $this->employee(['full_name' => 'Lead Person', 'manager_id' => $head->id], $this->login());
        $worker = $this->employee(['full_name' => 'Worker Person', 'manager_id' => $lead->id, 'birth_date' => '1990-05-01', 'personal_email' => 'worker.home@example.test'], $this->login());
        $peer = $this->employee(['full_name' => 'Peer Person', 'manager_id' => $lead->id], $this->login());
        $other = $this->employee(['full_name' => 'Other Person'], $this->login());

        return compact('head', 'lead', 'worker', 'peer', 'other');
    }

    protected function userOf(Employee $employee): User
    {
        $user = $employee->user()->first();
        assert($user instanceof User);

        return $user;
    }
}
