<?php

declare(strict_types=1);

namespace App\Modules\Perform\DTO;

use App\Modules\People\DTO\PeopleContext;

/**
 * Who is looking at performance data, computed once per request (PerformAccess::viewer):
 * admin (superadmin/admin = HR) — everything; manager — people below them in the org chart (People subtree);
 * employee — own items. departmentId drives "team" visibility of objectives.
 */
final readonly class PerformViewer
{
    public function __construct(
        public int $userId,
        public PeopleContext $ctx,
        public ?int $departmentId = null,
    ) {}

    public function admin(): bool
    {
        return $this->ctx->admin;
    }

    public function selfId(): ?int
    {
        return $this->ctx->selfId;
    }

    public function isSelf(int $employeeId): bool
    {
        return $this->ctx->isSelf($employeeId);
    }

    /** A manager above this employee (direct or indirect). */
    public function isAbove(int $employeeId): bool
    {
        return $this->ctx->isAbove($employeeId);
    }

    /** Manager duties for this employee: admin or a manager above them (never for oneself). */
    public function manages(int $employeeId): bool
    {
        return $this->admin() || $this->isAbove($employeeId);
    }

    /** Own data or data of people below: admin, self, managers above. */
    public function sees(int $employeeId): bool
    {
        return $this->manages($employeeId) || $this->isSelf($employeeId);
    }

    /**
     * Employees whose performance items are visible: null = all (admin).
     *
     * @return list<int>|null
     */
    public function visibleIds(): ?array
    {
        return $this->ctx->visibleIds();
    }
}
