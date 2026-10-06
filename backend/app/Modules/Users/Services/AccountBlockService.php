<?php

declare(strict_types=1);

namespace App\Modules\Users\Services;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Auth\Enums\UserStatus;
use App\Modules\Users\Contracts\AccountBlocker;
use App\Modules\Users\Contracts\UserAdminRepository;
use Psr\Log\LoggerInterface;

/** AccountBlocker over the users admin repository: the same lock, status change and credential revocation (PR #149). */
final readonly class AccountBlockService implements AccountBlocker
{
    public function __construct(
        private UserAdminRepository $users,
        private LoggerInterface $log,
    ) {}

    public function block(User $user, ?int $actorId): ?int
    {
        return $this->users->transaction(function () use ($user, $actorId): ?int {
            $this->users->lockAndRefresh($user);
            if (! $user->isActive()) {
                return null;
            }
            if (in_array(UserRole::Superadmin, $this->users->rolesOf($user), true) && $this->users->countActiveSuperadmins() <= 1) {
                $this->log->warning('users.lifecycle_block_skipped', ['user_id' => $user->id, 'reason' => 'last_superadmin']);

                return null;
            }
            $this->users->setStatus($user, UserStatus::Blocked);
            $this->users->revokeCredentials($user);
            $this->log->info('users.lifecycle_blocked', ['user_id' => $user->id, 'by' => $actorId]);

            return $user->credential_version;
        });
    }

    public function unblockIfBlockedBy(User $user, int $blockedVersion, ?int $actorId): bool
    {
        return $this->users->transaction(function () use ($user, $blockedVersion, $actorId): bool {
            $this->users->lockAndRefresh($user);
            if ($user->isActive() || $user->credential_version !== $blockedVersion) {
                return false;
            }
            $this->users->setStatus($user, UserStatus::Active);
            $this->log->info('users.lifecycle_unblocked', ['user_id' => $user->id, 'by' => $actorId]);

            return true;
        });
    }
}
