<?php

declare(strict_types=1);

namespace App\Modules\Overview\Providers;

use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Overview\Contracts\DashboardNotices;
use App\Modules\Overview\Contracts\DashboardRepository;
use App\Modules\Overview\Contracts\DashboardSection;
use App\Modules\Overview\Repositories\QueryDashboardRepository;
use App\Modules\Overview\Services\DashboardService;

/** Home page (dashboard): read-only aggregates over Recruiting and Scripts. Route: /api/dashboard. */
final class OverviewServiceProvider extends ModuleServiceProvider
{
    protected bool $coreModule = true;

    public function register(): void
    {
        $this->app->bind(DashboardRepository::class, QueryDashboardRepository::class);
        // Other modules add home-page warnings with $this->app->tag([...], DashboardNotices::class).
        $this->app->when(DashboardService::class)->needs('$notices')->giveTagged(DashboardNotices::class);
        // … and home-page blocks with $this->app->tag([...], DashboardSection::class) (data.<key>).
        $this->app->when(DashboardService::class)->needs('$sections')->giveTagged(DashboardSection::class);
    }
}
