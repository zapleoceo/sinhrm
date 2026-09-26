<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Services;

use App\Models\User;
use App\Modules\People\Models\Employee;
use App\Modules\People\Services\PeopleScope;
use App\Modules\Workflows\Contracts\AssigneeDirectory;
use App\Modules\Workflows\DTO\StepSnapshot;
use App\Modules\Workflows\Enums\AssigneeRule;

/**
 * Turns a step's assignee rule into a user id when the run starts. Returns null when the rule has nobody
 * (the employee or the manager has no login): task-creating executors then fall back to HR (hr()).
 */
final readonly class AssigneeResolver
{
    public function __construct(private AssigneeDirectory $users, private PeopleScope $scope) {}

    public function resolve(StepSnapshot $step, Employee $employee, ?User $startedBy): ?int
    {
        return match ($step->assigneeRule) {
            AssigneeRule::Employee => $this->active($employee->user_id),
            AssigneeRule::Manager => $this->active($employee->manager?->user_id),
            AssigneeRule::HrAdmin => $this->hr($startedBy),
            AssigneeRule::SpecificUser => $this->active($step->assigneeUserId),
        };
    }

    /** The admin who started the run, else the first active superadmin/admin. */
    public function hr(?User $startedBy = null): ?int
    {
        if ($startedBy !== null && $this->scope->isAdmin($startedBy)) {
            return $startedBy->id;
        }

        return $this->users->firstActiveAdminId();
    }

    private function active(?int $userId): ?int
    {
        return $userId === null ? null : $this->users->activeUser($userId)?->id;
    }
}
