<?php

declare(strict_types=1);

namespace App\Modules\Users\Repositories;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Auth\Enums\UserStatus;
use App\Modules\Core\Support\Database\Like;
use App\Modules\Users\Contracts\UserAdminRepository;
use App\Modules\Users\DTO\UserFilter;
use App\Modules\Users\Enums\UserSort;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use LogicException;

final class EloquentUserAdminRepository implements UserAdminRepository
{
    public function paginate(UserFilter $filter): LengthAwarePaginator
    {
        $query = User::query()
            ->with(['roles', 'branches'])
            // !== null, not truthy: a search for "0" is a real search.
            ->when($filter->q !== null, function (Builder $query) use ($filter): void {
                $like = Like::contains(mb_strtolower((string) $filter->q));
                $query->where(fn (Builder $w) => $w
                    ->whereRaw('lower(name) like ?', [$like])
                    ->orWhereRaw('lower(email) like ?', [$like]));
            })
            ->when($filter->status, fn (Builder $query, UserStatus $s) => $query->where('status', $s->value))
            ->when($filter->role, fn (Builder $query, UserRole $r) => $query->role($r->value))
            ->when($filter->lastLoginFrom, fn (Builder $query, Carbon $from) => $query->where('last_login_at', '>=', $from))
            ->when($filter->lastLoginTo, fn (Builder $query, Carbon $to) => $query->where('last_login_at', '<', $to));
        self::sort($query, $filter->sort, $filter->descending);

        return $query->paginate($filter->perPage);
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

    public function rolesOf(User $user): array
    {
        $names = $user->roles()->pluck('name')->all();

        return array_values(array_filter(UserRole::cases(), static fn (UserRole $r): bool => in_array($r->value, $names, true)));
    }

    public function setRoles(User $user, array $roles): void
    {
        $user->syncRoles(UserRole::valuesOf($roles));
    }

    public function setStatus(User $user, UserStatus $status): void
    {
        $user->forceFill(['status' => $status])->save();
    }

    public function lockAndRefresh(User $user): void
    {
        $fresh = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
        $user->setRawAttributes($fresh->getAttributes(), true);
        $user->unsetRelations();
    }

    public function revokeCredentials(User $user): void
    {
        // A separate session connection cannot participate in the users transaction: fail closed.
        $sessionConnection = config('session.connection') ?? DB::getDefaultConnection();
        if ($sessionConnection !== $user->getConnection()->getName()) {
            throw new LogicException('User credential revocation requires sessions on the users database connection.');
        }

        $user->getConnection()->table((string) config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        PersonalAccessToken::query()->whereMorphedTo('tokenable', $user)->delete();
        $user->forceFill([
            $user->getRememberTokenName() => null,
            'credential_version' => $user->credential_version + 1,
        ])->save();
    }

    public function setSafeSpeakHandler(User $user, bool $handler): void
    {
        $user->forceFill(['safe_speak_handler' => $handler])->save();
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
            User::query()->role(UserRole::Superadmin->value)->orderBy('id')->lockForUpdate()->pluck('id');

            return $callback();
        });
    }

    /**
     * ORDER BY of a whitelisted column: column and direction are literals from this match, never request text. Postgres puts NULLs first on
     * DESC: «nulls last» keeps «never logged in» at the end in both directions. Ties: by name, then id — stable pages.
     *
     * @param  Builder<User>  $query
     */
    private static function sort(Builder $query, UserSort $sort, bool $descending): void
    {
        $column = match ($sort) {
            UserSort::Name => 'users.name',
            UserSort::Status => 'users.status',
            UserSort::LastLogin => 'users.last_login_at',
        };
        $query->orderByRaw('case when '.$column.' is null then 1 else 0 end')
            ->orderBy($column, $descending ? 'desc' : 'asc')
            ->orderBy('users.name')
            ->orderBy('users.id');
    }
}
