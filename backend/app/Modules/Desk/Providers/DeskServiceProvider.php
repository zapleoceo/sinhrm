<?php

declare(strict_types=1);

namespace App\Modules\Desk\Providers;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Core\Contracts\NavBadgeProvider;
use App\Modules\Core\Contracts\ScheduledJob;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Desk\Contracts\DeskRepository;
use App\Modules\Desk\Repositories\EloquentDeskRepository;
use App\Modules\Desk\Services\DeskNavBadges;
use App\Modules\Desk\Services\DeskSlaJob;

/**
 * Desk: HR helpdesk cases (categories with SLA, thread with internal notes, attachments), the "desk.sla" job.
 * Routes: /api/desk/*.
 */
final class DeskServiceProvider extends ModuleServiceProvider
{
    protected string $moduleIcon = 'support_agent';

    protected string $moduleGroup = 'services';

    /** Queue, categories, assignment, internal notes: superadmin, admin (HR). */
    public const string MANAGE = 'desk-manage';

    /** Write endpoints (open a case, comment, attach): 20 requests per minute per user. */
    public const string WRITE_THROTTLE = 'throttle:20,1';

    protected string $prefix = 'desk';

    public function register(): void
    {
        $this->app->tag([DeskNavBadges::class], NavBadgeProvider::class);
        $this->app->bind(DeskRepository::class, EloquentDeskRepository::class);
        $this->app->tag([DeskSlaJob::class], ScheduledJob::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->defineRoleGate(self::MANAGE, UserRole::hrStaff());
    }
}
