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

    public function update(User $actor, User $target, ?UserRole $role, ?UserStatus $status): User
    {
        if ($role === null && $status === null) {
            return $target;
        }
        if ($actor->id === $target->id) {
            throw UserAdminException::selfChange();
        }

        $losesSuperadmin = ($role !== null && $role !== UserRole::Superadmin)
            || $status === UserStatus::Blocked;

        return $this->users->transaction(function () use ($actor, $target, $role, $status, $losesSuperadmin): User {
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
            $this->log->info('users.updated', [
                'user_id' => $target->id,
                'by' => $actor->id,
                'role' => $role?->value,
                'status' => $status?->value,
            ]);

            return $target;
        });
    }
}
