<?php

declare(strict_types=1);

namespace App\Modules\Users\Contracts;

use App\Models\User;

/**
 * Blocking a login for another module's lifecycle (People: termination / restore), not by an admin's hand.
 * Same effect as a block in the users admin: status blocked + every session, token and remember token revoked atomically.
 */
interface AccountBlocker
{
    /**
     * Block an active user. Returns the user's credential_version after the block (the caller keeps it to tell
     * "blocked by me" from a later manual block), or null when nothing was done: already blocked, or the last
     * active superadmin (never locked out by a lifecycle event).
     */
    public function block(User $user, ?int $actorId): ?int;

    /**
     * Unblock only if the user is still blocked by that block: status blocked and credential_version unchanged
     * since (any manual block increments it). Returns true when the user was unblocked.
     */
    public function unblockIfBlockedBy(User $user, int $blockedVersion, ?int $actorId): bool;
}
