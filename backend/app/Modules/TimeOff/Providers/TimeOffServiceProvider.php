<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Providers;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Core\Contracts\NavBadgeProvider;
use App\Modules\Core\Contracts\ScheduledJob;
use App\Modules\Core\Contracts\WorkingCalendar;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Overview\Contracts\DashboardSection;
use App\Modules\People\Events\EmployeeHired;
use App\Modules\TimeOff\Contracts\LeaveRequestRepository;
use App\Modules\TimeOff\Contracts\LeaveSettingsRepository;
use App\Modules\TimeOff\Contracts\LedgerRepository;
use App\Modules\TimeOff\Listeners\GrantAccrualOnHire;
use App\Modules\TimeOff\Repositories\EloquentLeaveRequestRepository;
use App\Modules\TimeOff\Repositories\EloquentLeaveSettingsRepository;
use App\Modules\TimeOff\Repositories\EloquentLedgerRepository;
use App\Modules\TimeOff\Services\AccrualJob;
use App\Modules\TimeOff\Services\HolidayWorkingCalendar;
use App\Modules\TimeOff\Services\TimeOffDashboardSection;
use App\Modules\TimeOff\Services\TimeOffNavBadges;
use Illuminate\Support\Facades\Event;

/**
 * TimeOff (leave): types, policies, holidays, balance ledger, requests with approval, team calendar, accrual job
 * ("timeoff.accrue"), dashboard block "timeoff",
 * WorkingCalendar (working days = Mon–Fri minus holidays) for SLA deadlines of all modules. Routes: /api/timeoff/*. Access rules come from People (PeopleScope); the manage gate is the HR-staff role gate.
 */
final class TimeOffServiceProvider extends ModuleServiceProvider
{
    protected string $moduleIcon = 'beach_access';

    protected string $moduleGroup = 'people';

    /** Leave types, policies, holidays, balance adjustments: HR staff (superadmin, admin, hr_manager). */
    public const string MANAGE = 'timeoff-manage';

    /** POST requests (a new leave request: days count, overlaps, notifications): 30 per minute per user, own bucket. */
    public const string REQUEST_LIMITER = 'timeoff-requests';

    public const string REQUEST_THROTTLE = 'throttle:'.self::REQUEST_LIMITER;

    public const int REQUESTS_PER_MINUTE = 30;

    protected string $prefix = 'timeoff';

    public function register(): void
    {
        $this->app->tag([TimeOffNavBadges::class], NavBadgeProvider::class);
        $this->app->bind(LeaveSettingsRepository::class, EloquentLeaveSettingsRepository::class);
        $this->app->bind(LedgerRepository::class, EloquentLedgerRepository::class);
        $this->app->bind(LeaveRequestRepository::class, EloquentLeaveRequestRepository::class);
        $this->app->bind(WorkingCalendar::class, HolidayWorkingCalendar::class);
        $this->app->tag([AccrualJob::class], ScheduledJob::class);
        $this->app->tag([TimeOffDashboardSection::class], DashboardSection::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->defineRoleGate(self::MANAGE, UserRole::hrStaff());
        $this->definePerUserLimiter(self::REQUEST_LIMITER, self::REQUESTS_PER_MINUTE);
        Event::listen(EmployeeHired::class, GrantAccrualOnHire::class);
    }
}
