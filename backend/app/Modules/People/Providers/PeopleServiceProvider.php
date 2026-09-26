<?php

declare(strict_types=1);

namespace App\Modules\People\Providers;

use App\Models\User;
use App\Modules\Core\Contracts\PersonalDataProvider;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\People\Contracts\ChangeRequestRepository;
use App\Modules\People\Contracts\EmployeeRepository;
use App\Modules\People\Privacy\EmployeePersonalData;
use App\Modules\People\Repositories\EloquentChangeRequestRepository;
use App\Modules\People\Repositories\EloquentEmployeeRepository;
use App\Modules\People\Services\PeopleScope;
use Illuminate\Support\Facades\Gate;

/**
 * People (Core HR): employees, directory, org chart, self-service change requests, hire from Recruiting.
 * Routes at the /api root: people, me/employee, applications/{id}/hire.
 */
final class PeopleServiceProvider extends ModuleServiceProvider
{
    /** Create/edit/terminate employees: superadmin, admin (there is no separate HR role yet). */
    public const string MANAGE = 'people-manage';

    public function register(): void
    {
        // Personal-data export/erase (Privacy module, docs/architecture/secrets.md).
        $this->app->tag([EmployeePersonalData::class], PersonalDataProvider::class);
        $this->app->bind(EmployeeRepository::class, EloquentEmployeeRepository::class);
        $this->app->bind(ChangeRequestRepository::class, EloquentChangeRequestRepository::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::define(self::MANAGE, fn (User $user): bool => $this->app->make(PeopleScope::class)->isAdmin($user));
    }
}
