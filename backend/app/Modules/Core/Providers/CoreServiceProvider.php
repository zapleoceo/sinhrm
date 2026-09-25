<?php

declare(strict_types=1);

namespace App\Modules\Core\Providers;

use App\Modules\Core\Contracts\HealthCheck;
use App\Modules\Core\Services\DatabaseHealthCheck;
use App\Modules\Core\Services\HealthService;
use App\Modules\Core\Support\ModuleServiceProvider;

final class CoreServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        // Other modules add their own checks with $this->app->tag([...], HealthCheck::class).
        $this->app->tag([DatabaseHealthCheck::class], HealthCheck::class);

        $this->app->bind(HealthService::class, fn ($app) => new HealthService($app->tagged(HealthCheck::class)));
    }
}
