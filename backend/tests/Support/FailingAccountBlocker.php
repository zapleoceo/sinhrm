<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\Users\Contracts\AccountBlocker;
use LogicException;

/** AccountBlocker that fails for one user (e.g. a credential revocation error) and delegates for everyone else. */
final readonly class FailingAccountBlocker implements AccountBlocker
{
    public function __construct(private AccountBlocker $inner, private int $failForUserId) {}

    public function block(User $user, ?int $actorId): ?int
    {
        if ($user->id === $this->failForUserId) {
            throw new LogicException('revocation failed');
        }

        return $this->inner->block($user, $actorId);
    }

    public function unblockIfBlockedBy(User $user, int $blockedVersion, ?int $actorId): bool
    {
        return $this->inner->unblockIfBlockedBy($user, $blockedVersion, $actorId);
    }
}
