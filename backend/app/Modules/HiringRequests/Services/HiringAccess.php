<?php

declare(strict_types=1);

namespace App\Modules\HiringRequests\Services;

use App\Models\User;
use App\Modules\HiringRequests\Contracts\HiringRequestRepository;
use App\Modules\HiringRequests\Enums\ApprovalStatus;
use App\Modules\HiringRequests\Enums\HiringRequestStatus;
use App\Modules\HiringRequests\Enums\RouteStepKind;
use App\Modules\HiringRequests\Models\HiringApproval;
use App\Modules\HiringRequests\Models\HiringRequest;
use App\Modules\People\Services\PeopleScope;

/**
 * Who may do what with hiring requests (tz2: "a separate right to create, a separate right to approve,
 * independent of the recruiter role"):
 * - create: superadmin/admin (HR), any manager (has reports in People), users listed in the settings;
 * - approve a step: its resolved approver (manager/user step), any active holder of the step's role, or an admin
 *   (HR override); never the requester unless admin;
 * - see: admin, the requester, the recruiter of the request, everyone on its route (approver, decider, role holders
 *   of a reached role step). Anybody else gets 404;
 * - vacancy, recruiter, close, route/form settings: admin.
 */
final readonly class HiringAccess
{
    public function __construct(private PeopleScope $people, private HiringRequestRepository $requests) {}

    public function isAdmin(User $user): bool
    {
        return $this->people->isAdmin($user);
    }

    public function canCreate(User $user): bool
    {
        if (! $user->isActive()) {
            return false;
        }
        if ($this->isAdmin($user) || $this->people->for($user)->isManager()) {
            return true;
        }

        return in_array($user->id, $this->requests->settings()->creator_user_ids ?? [], true);
    }

    /** @return list<string> */
    public function roles(User $user): array
    {
        return array_values(array_map('strval', $user->getRoleNames()->all()));
    }

    public function canSee(User $user, HiringRequest $request): bool
    {
        if (! $user->isActive()) {
            return false;
        }
        if ($this->isAdmin($user) || $request->requester_id === $user->id || $request->recruiter_id === $user->id) {
            return true;
        }
        $roles = $this->roles($user);
        foreach ($request->approvals as $a) {
            if ($a->approver_id === $user->id || $a->decided_by === $user->id) {
                return true;
            }
            if ($a->kind === RouteStepKind::Role && $a->status !== ApprovalStatus::Waiting && $a->status !== ApprovalStatus::Skipped
                && in_array($a->role, $roles, true)) {
                return true;
            }
        }

        return false;
    }

    public function canDecide(User $user, HiringRequest $request, ?HiringApproval $step): bool
    {
        if ($step === null || $step->status !== ApprovalStatus::Pending || $request->status !== HiringRequestStatus::Pending || ! $user->isActive()) {
            return false;
        }
        $admin = $this->isAdmin($user);
        if ($request->requester_id === $user->id && ! $admin) {
            return false;
        }

        return $admin
            || $step->approver_id === $user->id
            || ($step->kind === RouteStepKind::Role && $step->role !== null && in_array($step->role, $this->roles($user), true));
    }

    public function canEdit(User $user, HiringRequest $request): bool
    {
        return $request->status === HiringRequestStatus::Draft && ($request->requester_id === $user->id || $this->isAdmin($user));
    }

    public function canCancel(User $user, HiringRequest $request): bool
    {
        return $request->status->isCancellable() && ($request->requester_id === $user->id || $this->isAdmin($user));
    }

    /**
     * Flags for the UI.
     *
     * @return array{edit: bool, submit: bool, decide: bool, cancel: bool, manage: bool}
     */
    public function flags(User $user, HiringRequest $request): array
    {
        $manage = $this->isAdmin($user);

        return [
            'edit' => $this->canEdit($user, $request),
            'submit' => $this->canEdit($user, $request),
            'decide' => $this->canDecide($user, $request, $request->currentApproval()),
            'cancel' => $this->canCancel($user, $request),
            'manage' => $manage,
        ];
    }
}
