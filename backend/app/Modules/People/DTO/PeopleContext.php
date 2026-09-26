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

    /** Approve / reject requests (change requests, leave). Nobody but an admin decides their own. */
    public function canDecideFor(int $employeeId): bool
    {
        return $this->admin || $this->isAbove($employeeId);
    }

    /** @return array{job: bool, pii: bool, decide: bool, manage: bool, self: bool} */
    public function flags(int $employeeId): array
    {
        return [
            'job' => $this->canSeeJob($employeeId),
            'pii' => $this->canSeePii($employeeId),
            'decide' => $this->canDecideFor($employeeId),
            'manage' => $this->admin,
            'self' => $this->isSelf($employeeId),
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
