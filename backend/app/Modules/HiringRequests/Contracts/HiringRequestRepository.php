<?php

declare(strict_types=1);

namespace App\Modules\HiringRequests\Contracts;

use App\Modules\HiringRequests\Enums\ApprovalStatus;
use App\Modules\HiringRequests\Enums\HiringRequestStatus;
use App\Modules\HiringRequests\Models\HiringApproval;
use App\Modules\HiringRequests\Models\HiringRequest;
use App\Modules\HiringRequests\Models\HiringRouteStep;
use App\Modules\HiringRequests\Models\HiringSettings;
use Illuminate\Database\Eloquent\Collection;

interface HiringRequestRepository
{
    /**
     * Requests visible to the user: every request when $userId is null (admin); otherwise the ones they created,
     * recruit for, or appear on the route of (resolved approver, decider, or a role step of one of $roles).
     *
     * @param  list<string>  $roles
     * @param  array{status?: string|null, mine?: bool}  $filter
     * @return Collection<int, HiringRequest>
     */
    public function list(?int $userId, array $roles, array $filter, int $limit): Collection;

    public function find(int $id): ?HiringRequest;

    /** SELECT … FOR UPDATE inside a transaction (serializes vacancy creation for one request). */
    public function lock(int $id): ?HiringRequest;

    /**
     * Pending requests (with their current step) — the approval inbox is filtered by the service.
     *
     * @return Collection<int, HiringRequest>
     */
    public function pending(int $limit): Collection;

    /** @param  array<string, mixed>  $attributes */
    public function create(array $attributes): HiringRequest;

    /** @param  array<string, mixed>  $attributes */
    public function update(HiringRequest $request, array $attributes): void;

    /**
     * Compare-and-set of the request status: false when someone moved it first.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function transition(HiringRequest $request, HiringRequestStatus $from, array $attributes): bool;

    /** @param  list<array<string, mixed>>  $steps */
    public function createApprovals(HiringRequest $request, array $steps): void;

    /**
     * Compare-and-set of one step's status.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function transitionApproval(HiringApproval $approval, ApprovalStatus $from, array $attributes): bool;

    /** Marks remaining waiting/pending steps as skipped (cancel, reject). */
    public function skipOpenApprovals(HiringRequest $request): void;

    /** Sets notified = true once; false when it was already set (idempotency of the approver tasks). */
    public function markNotified(HiringApproval $approval): bool;

    /** Sets escalated = true once. */
    public function markEscalated(HiringApproval $approval): bool;

    /**
     * Pending steps (for the SLA job).
     *
     * @return Collection<int, HiringApproval>
     */
    public function pendingApprovals(): Collection;

    /**
     * In-progress requests with a vacancy (for auto-closing).
     *
     * @return Collection<int, HiringRequest>
     */
    public function inProgress(): Collection;

    /**
     * Hired applications per vacancy.
     *
     * @param  list<int>  $vacancyIds
     * @return array<int, int>
     */
    public function hiresByVacancy(array $vacancyIds): array;

    public function vacancyLinked(int $vacancyId, ?int $exceptRequestId): bool;

    /**
     * Active users that hold the role (role-step notifications), at most $limit.
     *
     * @return list<int>
     */
    public function usersWithRole(string $role, int $limit): array;

    public function isActiveUser(int $userId): bool;

    /**
     * Active users for the settings pickers (route user steps, creators, recruiter), by name.
     *
     * @return list<array{id: int, name: string}>
     */
    public function activeUsers(int $limit): array;

    public function settings(): HiringSettings;

    /** @param  array<string, mixed>  $attributes */
    public function saveSettings(array $attributes): HiringSettings;

    /** @return Collection<int, HiringRouteStep> ordered by position */
    public function routeSteps(): Collection;

    /** @param  list<array<string, mixed>>  $steps  replaces the route */
    public function replaceRoute(array $steps): void;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function transaction(callable $callback): mixed;
}
