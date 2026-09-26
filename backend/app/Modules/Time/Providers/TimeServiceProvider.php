<?php

declare(strict_types=1);

namespace App\Modules\Time\Providers;

use App\Models\User;
use App\Modules\Core\Contracts\NavBadgeProvider;
use App\Modules\Core\Contracts\ScheduledJob;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Overview\Contracts\DashboardSection;
use App\Modules\People\Services\PeopleScope;
use App\Modules\Time\Contracts\TimeRepository;
use App\Modules\Time\Repositories\EloquentTimeRepository;
use App\Modules\Time\Services\TimeDashboardSection;
use App\Modules\Time\Services\TimeNavBadges;
use App\Modules\Time\Services\TimeReminderJob;
use Illuminate\Support\Facades\Gate;

/**
 * Time: weekly timesheets with overtime vs the work schedule, manager approval, leave from TimeOff as absence,
 * the Friday reminder job "time.reminders", home page block "time". Routes: /api/time/*.
 */
final class TimeServiceProvider extends ModuleServiceProvider
{
    /** Work schedules: superadmin, admin. */
    public const string MANAGE = 'time-manage';

    protected string $prefix = 'time';

    public function register(): void
    {
        $this->app->tag([TimeNavBadges::class], NavBadgeProvider::class);
        $this->app->bind(TimeRepository::class, EloquentTimeRepository::class);
        $this->app->tag([TimeReminderJob::class], ScheduledJob::class);
        $this->app->tag([TimeDashboardSection::class], DashboardSection::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::define(self::MANAGE, fn (User $user): bool => $this->app->make(PeopleScope::class)->isAdmin($user));
    }
}
