<?php

declare(strict_types=1);

namespace App\Modules\People\DTO;

/**
 * What one user may do with employee records, computed once per request (PeopleScope::for):
 * - admin (HR staff: superadmin/admin/hr_manager) — everything;
 * - self — own profile incl. PII, own leave;
 * - manager — job data and leave of every employee below them (direct and indirect reports), approvals;
 * - everyone active — the directory tier only (name, position, branch, department, work contacts, manager).
 *
 * breakGlass: the user acts as superadmin and no OTHER active superadmin/admin exists (PeopleScope::for). Only then may
 * they decide their own requests (canDecideOrBreakGlass) — otherwise a sole administrator would be stuck.
 */
final readonly class PeopleContext
{
    /** @param  list<int>  $subtreeIds  employees below the user's own employee (self excluded) */
    public function __construct(
        public int $userId,
        public bool $admin,
        public ?int $selfId,
        public array $subtreeIds,
        public bool $breakGlass = false,
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
     * Role-only decision rule, own record included: HR staff over everyone, a manager over their subtree. Used by
     * timesheets (Time) and filing leave for someone — owner's decision: timesheet approval is governed by roles only,
     * an admin approves their own week (docs/modules/time.md). Requests, salary and leave balance use the stricter
     * canDecideOrBreakGlass.
     */
    public function canDecideFor(int $employeeId): bool
    {
        return $this->hasAuthorityOver($employeeId);
    }

    /**
     * Approve / reject change requests and leave, write someone's salary or leave balance. Separation of duties:
     * nobody decides their own, with one break-glass exception (default decision, the owner may change it): a
     * superadmin with no other active superadmin/admin decides their own — every such action is audited with
     * self_decision (SelfDecisionAudit). hr_manager, admin, or a superadmin who has a peer: 403 on their own record.
     */
    public function canDecideOrBreakGlass(int $employeeId): bool
    {
        return (! $this->isSelf($employeeId) && $this->hasAuthorityOver($employeeId)) || $this->isSelfDecision($employeeId);
    }

    /** The decision is allowed only by break-glass: own record of a sole superadmin. */
    public function isSelfDecision(int $employeeId): bool
    {
        return $this->breakGlass && $this->admin && $this->isSelf($employeeId);
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
            'decide' => $this->canDecideOrBreakGlass($employeeId),
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
