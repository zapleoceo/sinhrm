<?php

declare(strict_types=1);

namespace App\Modules\Users\Repositories;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Auth\Enums\UserStatus;
use App\Modules\Users\Contracts\UserAdminRepository;
use App\Modules\Users\DTO\UserFilter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class EloquentUserAdminRepository implements UserAdminRepository
{
    public function paginate(UserFilter $filter): LengthAwarePaginator
    {
        return User::query()
            ->with(['roles', 'branches'])
            ->when($filter->q, function (Builder $query, string $q): void {
                $like = '%'.addcslashes(mb_strtolower($q), '%_\\').'%';
                $query->where(fn (Builder $w) => $w
                    ->whereRaw('lower(name) like ?', [$like])
                    ->orWhereRaw('lower(email) like ?', [$like]));
            })
            ->when($filter->status, fn (Builder $query, UserStatus $s) => $query->where('status', $s->value))
            ->when($filter->role, fn (Builder $query, UserRole $r) => $query->role($r->value))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($filter->perPage);
    }

    public function emailExists(string $email): bool
    {
        return User::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->exists();
    }

    public function invite(string $email, string $name, UserRole $role, User $invitedBy): User
    {
        $user = new User;
        $user->forceFill([
            'email' => mb_strtolower($email),
            'name' => $name,
            'status' => UserStatus::Active,
            'invited_by' => $invitedBy->id,
        ])->save();
        $user->assignRole($role->value);

        return $user;
    }

    public function roleOf(User $user): ?UserRole
    {
        $name = $user->getRoleNames()->first();

        return is_string($name) ? UserRole::tryFrom($name) : null;
    }

    public function setRole(User $user, UserRole $role): void
    {
        $user->syncRoles([$role->value]);
    }

    public function setStatus(User $user, UserStatus $status): void
    {
        $user->forceFill(['status' => $status])->save();
    }

    public function syncBranches(User $user, array $branchIds): void
    {
        $user->branches()->sync($branchIds);
        $user->unsetRelation('branches');
    }

    public function countActiveSuperadmins(): int
    {
        return User::query()->role(UserRole::Superadmin->value)->where('status', UserStatus::Active->value)->count();
    }

    public function transaction(callable $callback): mixed
    {
        return DB::transaction(function () use ($callback): mixed {
            // Serialize concurrent changes of superadmins (last-superadmin rule).
            User::query()->role(UserRole::Superadmin->value)->lockForUpdate()->pluck('id');

            return $callback();
        });
    }
}
