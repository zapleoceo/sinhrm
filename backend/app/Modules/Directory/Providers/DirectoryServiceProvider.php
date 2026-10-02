<?php

declare(strict_types=1);

namespace App\Modules\Directory\Providers;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Directory\Contracts\AccessibleBranches;
use App\Modules\Directory\Contracts\DictionaryRepository;
use App\Modules\Directory\Repositories\EloquentDictionaryRepository;
use App\Modules\Directory\Services\BranchAccess;

final class DirectoryServiceProvider extends ModuleServiceProvider
{
    protected bool $coreModule = true;

    /** Create/edit/disable dictionary items: active superadmin or admin. */
    public const string MANAGE_DIRECTORY = 'manage-directory';

    protected string $prefix = 'directory';

    public function register(): void
    {
        $this->app->bind(DictionaryRepository::class, EloquentDictionaryRepository::class);
        $this->app->bind(AccessibleBranches::class, BranchAccess::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->defineRoleGate(self::MANAGE_DIRECTORY, [UserRole::Superadmin, UserRole::Admin]);
    }
}
