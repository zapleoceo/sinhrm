<?php

declare(strict_types=1);

namespace App\Modules\Users\Providers;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Users\Contracts\UserAdminRepository;
use App\Modules\Users\Repositories\EloquentUserAdminRepository;
use Illuminate\Support\Facades\Gate;

final class UsersServiceProvider extends ModuleServiceProvider
{
    /** Ability guarding the users admin. Only superadmin for now (admin role will get it later). */
    public const string MANAGE_USERS = 'manage-users';

    protected string $prefix = 'users';

    public function register(): void
    {
        $this->app->bind(UserAdminRepository::class, EloquentUserAdminRepository::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::define(self::MANAGE_USERS, fn (User $user): bool => $user->isActive()
            && $user->hasRole(UserRole::Superadmin->value));
    }
}
