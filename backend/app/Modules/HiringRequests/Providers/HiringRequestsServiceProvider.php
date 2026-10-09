<?php

declare(strict_types=1);

namespace App\Modules\HiringRequests\Providers;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Core\Contracts\NavBadgeProvider;
use App\Modules\Core\Contracts\ScheduledJob;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\HiringRequests\Contracts\HiringRequestRepository;
use App\Modules\HiringRequests\Repositories\EloquentHiringRequestRepository;
use App\Modules\HiringRequests\Services\HiringDashboardSection;
use App\Modules\HiringRequests\Services\HiringNavBadges;
use App\Modules\HiringRequests\Services\HiringSlaJob;
use App\Modules\Overview\Contracts\DashboardSection;

/**
 * HiringRequests (tz2): requests to hire with a configurable approval route, SLA per step, auto-vacancy.
 * Routes: /api/hiring-requests/*; job "hiring.sla"; home page block "hiring".
 */
final class HiringRequestsServiceProvider extends ModuleServiceProvider
{
    protected string $moduleIcon = 'assignment_add';

    protected string $moduleGroup = 'recruiting';

    /** Settings, vacancy link, closing: HR staff (UserRole::hrStaff(): superadmin, admin, hr_manager). */
    public const string MANAGE = 'hiring-manage';

    protected string $prefix = 'hiring-requests';

    public function register(): void
    {
        $this->app->tag([HiringNavBadges::class], NavBadgeProvider::class);
        $this->app->bind(HiringRequestRepository::class, EloquentHiringRequestRepository::class);
        $this->app->tag([HiringSlaJob::class], ScheduledJob::class);
        $this->app->tag([HiringDashboardSection::class], DashboardSection::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->defineRoleGate(self::MANAGE, UserRole::hrStaff());
    }
}
