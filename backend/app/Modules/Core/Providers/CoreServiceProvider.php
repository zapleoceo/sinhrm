<?php

declare(strict_types=1);

namespace App\Modules\Core\Providers;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Core\Contracts\HealthCheck;
use App\Modules\Core\Contracts\MigrationRunner;
use App\Modules\Core\Contracts\ModuleSettingsRepository;
use App\Modules\Core\Contracts\NavBadgeProvider;
use App\Modules\Core\Contracts\ScheduledJob;
use App\Modules\Core\Http\Controllers\OpsJobsController;
use App\Modules\Core\Repositories\EloquentModuleSettingsRepository;
use App\Modules\Core\Services\ArtisanMigrationRunner;
use App\Modules\Core\Services\DatabaseHealthCheck;
use App\Modules\Core\Services\HealthService;
use App\Modules\Core\Services\ModuleAccess;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Services\NavBadgeService;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Core\Support\NeonConnectionConfig;
use Illuminate\Support\Facades\Gate;

final class CoreServiceProvider extends ModuleServiceProvider
{
    /** Who opens the "Модулі" admin page (GET/PUT /api/modules): superadmin only. */
    public const string MANAGE_MODULES = 'manage-modules';

    protected bool $coreModule = true;

    public function register(): void
    {
        $config = $this->app['config'];
        $config->set('database.connections.pgsql', NeonConnectionConfig::apply($config->get('database.connections.pgsql')));

        // Other modules add their own checks with $this->app->tag([...], HealthCheck::class).
        $this->app->tag([DatabaseHealthCheck::class], HealthCheck::class);

        $this->app->bind(MigrationRunner::class, ArtisanMigrationRunner::class);

        $this->app->bind(HealthService::class, fn ($app) => new HealthService($app->tagged(HealthCheck::class)));

        // Background jobs for the cron workflow: modules add theirs with $this->app->tag([...], ScheduledJob::class).
        $this->app->bind(OpsJobsController::class, fn ($app) => new OpsJobsController($app->tagged(ScheduledJob::class), $app->make(ModuleAccess::class), $app->make(ModuleRegistry::class)));

        // Sidebar counters (GET /api/nav/badges): modules add theirs with $this->app->tag([...], NavBadgeProvider::class).
        $this->app->bind(NavBadgeService::class, fn ($app) => new NavBadgeService(
            $app->tagged(NavBadgeProvider::class),
            $app['cache.store'],
            $app->make(ModuleAccess::class),
            $app->make(ModuleRegistry::class),
        ));

        // Module access (docs/modules/modules-access.md): registry filled by every ModuleServiceProvider::boot.
        $this->app->singleton(ModuleRegistry::class);
        $this->app->bind(ModuleSettingsRepository::class, EloquentModuleSettingsRepository::class);
        $this->app->scoped(ModuleAccess::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::define(self::MANAGE_MODULES, fn (User $user): bool => $user->isActive() && $user->hasRole(UserRole::Superadmin->value));
    }
}
