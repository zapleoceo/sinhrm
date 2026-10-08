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

    /** Queue, categories, assignment, internal notes: HR staff (UserRole::hrStaff(): superadmin, admin, hr_manager). */
    public const string MANAGE = 'desk-manage';

    /**
     * Write endpoints (open a case, comment, attach): 20 requests per minute per user, in Desk's own bucket. A plain
     * "throttle:20,1" keys on the user only, so it shared one counter with every other "throttle:N,1" route
     * (Channels, Recruiting): sending messages could lock a user out of filing a case, and the other way round.
     */
    public const string WRITE_LIMITER = 'desk-write';

    public const string WRITE_THROTTLE = 'throttle:'.self::WRITE_LIMITER;

    public const int WRITES_PER_MINUTE = 20;

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

        $this->definePerUserLimiter(self::WRITE_LIMITER, self::WRITES_PER_MINUTE);
    }
}
