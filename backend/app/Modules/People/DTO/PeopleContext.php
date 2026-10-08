<?php

declare(strict_types=1);

namespace App\Modules\People\DTO;

/**
 * What one user may do with employee records, computed once per request (PeopleScope::for):
 * - admin (HR staff: superadmin/admin/hr_manager) — everything;
 * - self — own profile incl. PII, own leave;
 * - manager — job data and leave of every employee below them (direct and indirect reports), approvals;
 * - everyone active — the directory tier only (name, position, branch, department, work contacts, manager).
 */
final readonly class PeopleContext
{
    /** @param  list<int>  $subtreeIds  employees below the user's own employee (self excluded) */
    public function __construct(
        public int $userId,
        public bool $admin,
        public ?int $selfId,
        public array $subtreeIds,
    ) {}

    public function isManager(): bool
    {
        return $this->subtreeIds !== [];
    }

    public function isSelf(int $employeeId): bool
    {
        return $this->selfId === $employeeId;
    }

    public function isAbove(int $employeeId): bool
    {
        return in_array($employeeId, $this->subtreeIds, true);
    }

    /** Hire date, employment type, schedule, leave data. */
    public function canSeeJob(int $employeeId): bool
    {
        return $this->admin || $this->isSelf($employeeId) || $this->isAbove($employeeId);
    }

    /** Birth date, personal contacts, custom fields. */
    public function canSeePii(int $employeeId): bool
    {
        return $this->admin || $this->isSelf($employeeId);
    }

    /**
     * Authority over someone's record: HR staff over everyone, a manager over their subtree. Own record included —
     * use it only where acting on oneself is legitimate (cancelling own leave), never for a decision.
     */
    public function hasAuthorityOver(int $employeeId): bool
    {
        return $this->admin || $this->isAbove($employeeId);
    }

    /**
     * Approve / reject requests (change requests, leave) and write someone's salary or leave balance.
     * Separation of duties: NOBODY decides their own, superadmin included — a second pair of eyes always signs off.
     * A sole superadmin who is also an employee therefore files requests like everyone else and has them approved by
     * another admin; bootstrapping the system does not need this path (HR data is written directly, not requested).
     */
    public function canDecideFor(int $employeeId): bool
    {
        return ! $this->isSelf($employeeId) && $this->hasAuthorityOver($employeeId);
    }

    /**
     * Terminate (now or from a date) and cancel a scheduled termination: HR staff or a manager above along the
     * manager_id chain. Nobody terminates their own record (the login would lock itself out).
     */
    public function canTerminate(int $employeeId): bool
    {
        return ! $this->isSelf($employeeId) && $this->hasAuthorityOver($employeeId);
    }

    /** @return array{job: bool, pii: bool, decide: bool, manage: bool, self: bool, terminate: bool} */
    public function flags(int $employeeId): array
    {
        return [
            'job' => $this->canSeeJob($employeeId),
            'pii' => $this->canSeePii($employeeId),
            'decide' => $this->canDecideFor($employeeId),
            'manage' => $this->admin,
            'self' => $this->isSelf($employeeId),
            'terminate' => $this->canTerminate($employeeId),
        ];
    }

    /**
     * Employees whose job/leave data is visible: null = all (admin).
     *
     * @return list<int>|null
     */
    public function visibleIds(): ?array
    {
        if ($this->admin) {
            return null;
        }

        return $this->selfId === null ? $this->subtreeIds : [$this->selfId, ...$this->subtreeIds];
    }
}
