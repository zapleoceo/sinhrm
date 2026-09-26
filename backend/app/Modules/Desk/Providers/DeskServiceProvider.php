<?php

declare(strict_types=1);

namespace App\Modules\Desk\Providers;

use App\Models\User;
use App\Modules\Core\Contracts\ScheduledJob;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Desk\Contracts\DeskRepository;
use App\Modules\Desk\Repositories\EloquentDeskRepository;
use App\Modules\Desk\Services\DeskSlaJob;
use App\Modules\People\Services\PeopleScope;
use Illuminate\Support\Facades\Gate;

/**
 * Desk: HR helpdesk cases (categories with SLA, thread with internal notes, attachments), the "desk.sla" job.
 * Routes: /api/desk/*.
 */
final class DeskServiceProvider extends ModuleServiceProvider
{
    /** Queue, categories, assignment, internal notes: superadmin, admin (HR). */
    public const string MANAGE = 'desk-manage';

    protected string $prefix = 'desk';

    public function register(): void
    {
        $this->app->bind(DeskRepository::class, EloquentDeskRepository::class);
        $this->app->tag([DeskSlaJob::class], ScheduledJob::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::define(self::MANAGE, fn (User $user): bool => $this->app->make(PeopleScope::class)->isAdmin($user));
    }
}
