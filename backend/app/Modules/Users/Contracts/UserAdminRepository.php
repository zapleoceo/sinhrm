<?php

declare(strict_types=1);

namespace App\Modules\Users\Contracts;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Auth\Enums\UserStatus;
use App\Modules\Users\DTO\UserFilter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface UserAdminRepository
{
    /** @return LengthAwarePaginator<int, User> */
    public function paginate(UserFilter $filter): LengthAwarePaginator;

    /** Case-insensitive. */
    public function emailExists(string $email): bool;

    public function invite(string $email, string $name, UserRole $role, User $invitedBy): User;

    public function roleOf(User $user): ?UserRole;

    public function setRole(User $user, UserRole $role): void;

    public function setStatus(User $user, UserStatus $status): void;

    /** @param  list<int>  $branchIds  replaces the user's branches (Directory module, table branch_user) */
    public function syncBranches(User $user, array $branchIds): void;

    public function setSafeSpeakHandler(User $user, bool $handler): void;

    public function countActiveSuperadmins(): int;

    /**
     * Runs the callback atomically (the last-superadmin check and the change must not interleave).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function transaction(callable $callback): mixed;
}
