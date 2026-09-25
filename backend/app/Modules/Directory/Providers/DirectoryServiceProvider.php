<?php

declare(strict_types=1);

namespace App\Modules\Directory\Providers;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Directory\Contracts\AccessibleBranches;
use App\Modules\Directory\Contracts\DictionaryRepository;
use App\Modules\Directory\Contracts\DirectoryImporter;
use App\Modules\Directory\Repositories\EloquentDictionaryRepository;
use App\Modules\Directory\Services\BranchAccess;
use App\Modules\Directory\Services\SintegrumDirectoryImporter;
use Illuminate\Support\Facades\Gate;

final class DirectoryServiceProvider extends ModuleServiceProvider
{
    /** Create/edit/disable dictionary items: active superadmin or admin. */
    public const string MANAGE_DIRECTORY = 'manage-directory';

    /** Run the Sintegrum import (uses integration secrets): active superadmin only. */
    public const string IMPORT_DIRECTORY = 'import-directory';

    protected string $prefix = 'directory';

    public function register(): void
    {
        $this->app->bind(DictionaryRepository::class, EloquentDictionaryRepository::class);
        $this->app->bind(AccessibleBranches::class, BranchAccess::class);
        $this->app->bind(DirectoryImporter::class, SintegrumDirectoryImporter::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::define(self::MANAGE_DIRECTORY, fn (User $user): bool => $user->isActive()
            && $user->hasAnyRole([UserRole::Superadmin->value, UserRole::Admin->value]));
        Gate::define(self::IMPORT_DIRECTORY, fn (User $user): bool => $user->isActive()
            && $user->hasRole(UserRole::Superadmin->value));
    }
}
