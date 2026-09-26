<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Providers;

use App\Models\User;
use App\Modules\Core\Contracts\ScheduledJob;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Overview\Contracts\DashboardSection;
use App\Modules\People\Events\EmployeeHired;
use App\Modules\People\Services\PeopleScope;
use App\Modules\TimeOff\Contracts\LeaveRequestRepository;
use App\Modules\TimeOff\Contracts\LeaveSettingsRepository;
use App\Modules\TimeOff\Contracts\LedgerRepository;
use App\Modules\TimeOff\Listeners\GrantAccrualOnHire;
use App\Modules\TimeOff\Repositories\EloquentLeaveRequestRepository;
use App\Modules\TimeOff\Repositories\EloquentLeaveSettingsRepository;
use App\Modules\TimeOff\Repositories\EloquentLedgerRepository;
use App\Modules\TimeOff\Services\AccrualJob;
use App\Modules\TimeOff\Services\TimeOffDashboardSection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

/**
 * TimeOff (leave): types, policies, holidays, balance ledger, requests with approval, team calendar, accrual job
 * ("timeoff.accrue"), dashboard block "timeoff". Routes: /api/timeoff/*. Access rules come from People (PeopleScope).
 */
final class TimeOffServiceProvider extends ModuleServiceProvider
{
    /** Leave types, policies, holidays, balance adjustments: superadmin, admin. */
    public const string MANAGE = 'timeoff-manage';

    protected string $prefix = 'timeoff';

    public function register(): void
    {
        $this->app->bind(LeaveSettingsRepository::class, EloquentLeaveSettingsRepository::class);
        $this->app->bind(LedgerRepository::class, EloquentLedgerRepository::class);
        $this->app->bind(LeaveRequestRepository::class, EloquentLeaveRequestRepository::class);
        $this->app->tag([AccrualJob::class], ScheduledJob::class);
        $this->app->tag([TimeOffDashboardSection::class], DashboardSection::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::define(self::MANAGE, fn (User $user): bool => $this->app->make(PeopleScope::class)->isAdmin($user));
        Event::listen(EmployeeHired::class, GrantAccrualOnHire::class);
    }
}
