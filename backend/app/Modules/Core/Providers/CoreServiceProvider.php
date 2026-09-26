<?php

declare(strict_types=1);

namespace App\Modules\Core\Providers;

use App\Modules\Core\Contracts\HealthCheck;
use App\Modules\Core\Contracts\MigrationRunner;
use App\Modules\Core\Contracts\NavBadgeProvider;
use App\Modules\Core\Contracts\ScheduledJob;
use App\Modules\Core\Http\Controllers\OpsJobsController;
use App\Modules\Core\Services\ArtisanMigrationRunner;
use App\Modules\Core\Services\DatabaseHealthCheck;
use App\Modules\Core\Services\HealthService;
use App\Modules\Core\Services\NavBadgeService;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Core\Support\NeonConnectionConfig;

final class CoreServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $config = $this->app['config'];
        $config->set('database.connections.pgsql', NeonConnectionConfig::apply($config->get('database.connections.pgsql')));

        // Other modules add their own checks with $this->app->tag([...], HealthCheck::class).
        $this->app->tag([DatabaseHealthCheck::class], HealthCheck::class);

        $this->app->bind(MigrationRunner::class, ArtisanMigrationRunner::class);

        $this->app->bind(HealthService::class, fn ($app) => new HealthService($app->tagged(HealthCheck::class)));

        // Background jobs for the cron workflow: modules add theirs with $this->app->tag([...], ScheduledJob::class).
        $this->app->bind(OpsJobsController::class, fn ($app) => new OpsJobsController($app->tagged(ScheduledJob::class)));

        // Sidebar counters (GET /api/nav/badges): modules add theirs with $this->app->tag([...], NavBadgeProvider::class).
        $this->app->bind(NavBadgeService::class, fn ($app) => new NavBadgeService($app->tagged(NavBadgeProvider::class), $app['cache.store']));
    }
}
