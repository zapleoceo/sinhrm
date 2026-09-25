<?php

declare(strict_types=1);

namespace App\Modules\Overview\Providers;

use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Overview\Contracts\DashboardRepository;
use App\Modules\Overview\Repositories\QueryDashboardRepository;

/** Home page (dashboard): read-only aggregates over Recruiting and Scripts. Route: /api/dashboard. */
final class OverviewServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DashboardRepository::class, QueryDashboardRepository::class);
    }
}
