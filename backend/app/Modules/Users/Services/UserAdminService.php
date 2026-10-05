<?php

declare(strict_types=1);

namespace App\Modules\Users\Services;

use App\Models\User;
use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Auth\Enums\UserStatus;
use App\Modules\Users\Contracts\UserAdminRepository;
use App\Modules\Users\DTO\UserFilter;
use App\Modules\Users\Exceptions\UserAdminException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Psr\Log\LoggerInterface;

final class UserAdminService
{
    public function __construct(
        private readonly UserAdminRepository $users,
        private readonly LoggerInterface $log,
        private readonly AuditLogger $audit,
    ) {}

    /** @return LengthAwarePaginator<int, User> */
    public function list(UserFilter $filter): LengthAwarePaginator
    {
        return $this->users->paginate($filter);
    }

    /** Invited users have no password: they sign in with Google using the same e-mail. */
    public function invite(User $actor, string $email, string $name, UserRole $role): User
    {
        if ($this->users->emailExists($email)) {
            throw UserAdminException::emailTaken();
        }

        $user = $this->users->invite($email, $name, $role, $actor);
        // Audit without personal data: ids and role only.
        $this->audit->record('user', $user->id, AuditAction::RoleChanged, ['role' => ['from' => null, 'to' => $role->value]], null, $actor->id);
        $this->log->info('users.invited', ['user_id' => $user->id, 'invited_by' => $actor->id, 'role' => $role->value]);

        return $user;
    }

    /**
     * @param  list<UserRole>|null  $roles  null = unchanged; otherwise the full new set of global roles (at least one).
     *                                      Superadmin may be kept on a user who has it, never given.
     * @param  list<int>|null  $branchIds  null = unchanged; a list replaces the user's branches
     * @param  bool|null  $safeSpeakHandler  null = unchanged; true only for HR staff — superadmin/admin/hr_manager (may be set on oneself)
     */
    public function update(User $actor, User $target, ?array $roles, ?UserStatus $status, ?array $branchIds = null, ?bool $safeSpeakHandler = null): User
    {
        if ($roles === null && $status === null && $branchIds === null && $safeSpeakHandler === null) {
            return $target;
        }
        if ($actor->id === $target->id && ($roles !== null || $status !== null || $branchIds !== null)) {
            throw UserAdminException::selfChange();
        }
        if ($roles !== null) {
            $roles = array_values(array_filter(UserRole::cases(), static fn (UserRole $r): bool => in_array($r, $roles, true)));
        }

        return $this->users->transaction(function () use ($actor, $target, $roles, $status, $branchIds, $safeSpeakHandler): User {
            $this->users->lockAndRefresh($target);
            $previous = $this->users->rolesOf($target);
            $wasSuperadmin = in_array(UserRole::Superadmin, $previous, true);
            if ($roles !== null && ! $wasSuperadmin && in_array(UserRole::Superadmin, $roles, true)) {
                throw UserAdminException::superadminNotAssignable();
            }
            $losesSuperadmin = ($roles !== null && ! in_array(UserRole::Superadmin, $roles, true))
                || $status === UserStatus::Blocked;
            if ($losesSuperadmin
                && $wasSuperadmin
                && $target->isActive()
                && $this->users->countActiveSuperadmins() <= 1) {
                throw UserAdminException::lastSuperadmin();
            }

            if ($roles !== null && $roles !== [] && $roles !== $previous) {
                $this->users->setRoles($target, $roles);
                $this->audit->record('user', $target->id, AuditAction::RoleChanged, ['role' => ['from' => self::names($previous), 'to' => self::names($roles)]], null, $actor->id);
            }
            if ($status !== null) {
                $this->users->setStatus($target, $status);
                if ($status === UserStatus::Blocked) {
                    $this->users->revokeCredentials($target);
                }
            }
            if ($branchIds !== null) {
                $this->users->syncBranches($target, $branchIds);
            }
            $isAdmin = array_intersect(UserRole::valuesOf($roles ?? $previous), UserRole::valuesOf(UserRole::hrStaff())) !== [];
            if ($safeSpeakHandler === true && ! $isAdmin) {
                throw UserAdminException::handlerRequiresAdmin();
            }
            if ($safeSpeakHandler !== null) {
                $this->users->setSafeSpeakHandler($target, $safeSpeakHandler);
            } elseif (! $isAdmin && $target->safe_speak_handler) {
                // Demoted from admin: the handler flag goes with the role.
                $this->users->setSafeSpeakHandler($target, false);
            }
            $this->log->info('users.updated', [
                'user_id' => $target->id,
                'by' => $actor->id,
                'roles' => $roles === null ? null : UserRole::valuesOf($roles),
                'status' => $status?->value,
                'branch_ids' => $branchIds,
                'safe_speak_handler' => $safeSpeakHandler,
            ]);

            return $target;
        });
    }

    /**
     * Audit value: "recruiter" or "admin, recruiter" (null = no roles).
     *
     * @param  list<UserRole>  $roles
     */
    private static function names(array $roles): ?string
    {
        return $roles === [] ? null : implode(', ', UserRole::valuesOf($roles));
    }
}
