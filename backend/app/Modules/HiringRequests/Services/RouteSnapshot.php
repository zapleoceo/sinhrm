<?php

declare(strict_types=1);

namespace App\Modules\HiringRequests\Services;

use App\Modules\HiringRequests\Contracts\HiringRequestRepository;
use App\Modules\HiringRequests\Enums\ApprovalStatus;
use App\Modules\HiringRequests\Enums\RouteStepKind;
use App\Modules\HiringRequests\Models\HiringRequest;
use App\Modules\People\Contracts\EmployeeRepository;

/**
 * The approval route template → the steps of one request, copied on submit. Each step gets its approver: the
 * requester's line manager (People), a fixed user or a role (any holder decides). Unresolvable steps — no manager with
 * a login, self-approval, inactive user, role step without a role — are stored as skipped.
 */
final readonly class RouteSnapshot
{
    public function __construct(
        private HiringRequestRepository $requests,
        private EmployeeRepository $employees,
    ) {}

    /** @return list<array<string, mixed>> */
    public function of(HiringRequest $request): array
    {
        $steps = [];
        foreach ($this->requests->routeSteps() as $step) {
            $approver = match ($step->kind) {
                RouteStepKind::Manager => $this->managerUserId($request->requester_id),
                RouteStepKind::User => $step->user_id,
                RouteStepKind::Role => null,
            };
            $skip = match ($step->kind) {
                RouteStepKind::Role => $step->role === null,
                default => $approver === null || $approver === $request->requester_id || ! $this->requests->isActiveUser($approver),
            };
            $steps[] = [
                'position' => $step->position,
                'name' => $step->name,
                'kind' => $step->kind->value,
                'role' => $step->kind === RouteStepKind::Role ? $step->role : null,
                'approver_id' => $skip ? null : $approver,
                'sla_days' => $step->sla_days,
                'status' => ($skip ? ApprovalStatus::Skipped : ApprovalStatus::Waiting)->value,
            ];
        }

        return $steps;
    }

    private function managerUserId(?int $requesterId): ?int
    {
        if ($requesterId === null) {
            return null;
        }
        $self = $this->employees->findByUser($requesterId);
        $manager = $self?->manager_id === null ? null : $this->employees->find($self->manager_id);

        return $manager?->user_id;
    }
}
