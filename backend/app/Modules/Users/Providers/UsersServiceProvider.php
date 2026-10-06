<?php

declare(strict_types=1);

namespace App\Modules\Users\Providers;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Users\Contracts\AccountBlocker;
use App\Modules\Users\Contracts\UserAdminRepository;
use App\Modules\Users\Repositories\EloquentUserAdminRepository;
use App\Modules\Users\Services\AccountBlockService;

final class UsersServiceProvider extends ModuleServiceProvider
{
    protected bool $coreModule = true;

    /** Ability guarding the users admin. Only superadmin for now (admin role will get it later). */
    public const string MANAGE_USERS = 'manage-users';

    protected string $prefix = 'users';

    public function register(): void
    {
        $this->app->bind(UserAdminRepository::class, EloquentUserAdminRepository::class);
        $this->app->bind(AccountBlocker::class, AccountBlockService::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->defineRoleGate(self::MANAGE_USERS, [UserRole::Superadmin]);
    }
}
