<?php

declare(strict_types=1);

namespace App\Modules\Users\Services;

use App\Models\User;
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
        $this->log->info('users.invited', ['user_id' => $user->id, 'invited_by' => $actor->id, 'role' => $role->value]);

        return $user;
    }

    /**
     * @param  list<int>|null  $branchIds  null = unchanged; a list replaces the user's branches
     * @param  bool|null  $safeSpeakHandler  null = unchanged; true only for superadmin/admin (may be set on oneself)
     */
    public function update(User $actor, User $target, ?UserRole $role, ?UserStatus $status, ?array $branchIds = null, ?bool $safeSpeakHandler = null): User
    {
        if ($role === null && $status === null && $branchIds === null && $safeSpeakHandler === null) {
            return $target;
        }
        if ($actor->id === $target->id && ($role !== null || $status !== null || $branchIds !== null)) {
            throw UserAdminException::selfChange();
        }

        $losesSuperadmin = ($role !== null && $role !== UserRole::Superadmin)
            || $status === UserStatus::Blocked;

        return $this->users->transaction(function () use ($actor, $target, $role, $status, $branchIds, $safeSpeakHandler, $losesSuperadmin): User {
            if ($losesSuperadmin
                && $target->isActive()
                && $this->users->roleOf($target) === UserRole::Superadmin
                && $this->users->countActiveSuperadmins() <= 1) {
                throw UserAdminException::lastSuperadmin();
            }

            if ($role !== null) {
                $this->users->setRole($target, $role);
            }
            if ($status !== null) {
                $this->users->setStatus($target, $status);
            }
            if ($branchIds !== null) {
                $this->users->syncBranches($target, $branchIds);
            }
            $isAdmin = in_array($role ?? $this->users->roleOf($target), [UserRole::Superadmin, UserRole::Admin], true);
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
                'role' => $role?->value,
                'status' => $status?->value,
                'branch_ids' => $branchIds,
                'safe_speak_handler' => $safeSpeakHandler,
            ]);

            return $target;
        });
    }
}
