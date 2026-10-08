<?php

declare(strict_types=1);

namespace App\Modules\People\Providers;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Core\Contracts\PersonalDataProvider;
use App\Modules\Core\Contracts\ScheduledJob;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\People\Contracts\ChangeRequestRepository;
use App\Modules\People\Contracts\EmployeeLookup;
use App\Modules\People\Contracts\EmployeeRepository;
use App\Modules\People\Contracts\PeopleAccess;
use App\Modules\People\Contracts\PickerUserRepository;
use App\Modules\People\Privacy\CompensationPersonalData;
use App\Modules\People\Privacy\EmployeePersonalData;
use App\Modules\People\Repositories\EloquentChangeRequestRepository;
use App\Modules\People\Repositories\EloquentEmployeeRepository;
use App\Modules\People\Repositories\EloquentPickerUserRepository;
use App\Modules\People\Services\EmployeeService;
use App\Modules\People\Services\PeopleScope;
use App\Modules\People\Services\ScheduledTerminationJob;

/**
 * People (Core HR): employees, directory, org chart, self-service change requests, hire from Recruiting.
 * Routes at the /api root: people, me/employee, applications/{id}/hire.
 */
final class PeopleServiceProvider extends ModuleServiceProvider
{
    protected string $moduleIcon = 'groups';

    protected string $moduleGroup = 'people';

    /** Create/edit/restore employees: HR staff (superadmin, admin, hr_manager). Termination: also managers above. */
    public const string MANAGE = 'people-manage';

    public function register(): void
    {
        // Personal-data export/erase (Privacy module, docs/architecture/secrets.md).
        $this->app->tag([EmployeePersonalData::class, CompensationPersonalData::class], PersonalDataProvider::class);
        $this->app->bind(EmployeeRepository::class, EloquentEmployeeRepository::class);
        $this->app->bind(ChangeRequestRepository::class, EloquentChangeRequestRepository::class);
        $this->app->bind(PickerUserRepository::class, EloquentPickerUserRepository::class);
        // Contracts for other modules (docs/architecture/overview.md, "Границы модулей").
        $this->app->bind(PeopleAccess::class, PeopleScope::class);
        $this->app->bind(EmployeeLookup::class, EmployeeService::class);
        // Scheduled terminations come into force on their date (cron, POST /api/ops/jobs/run).
        $this->app->tag([ScheduledTerminationJob::class], ScheduledJob::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->defineRoleGate(self::MANAGE, UserRole::hrStaff());
    }
}
