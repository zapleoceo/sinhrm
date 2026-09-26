<?php

declare(strict_types=1);

namespace App\Modules\Perform\Providers;

use App\Models\User;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\People\Services\PeopleScope;
use App\Modules\Perform\Contracts\DevelopmentPlanRepository;
use App\Modules\Perform\Contracts\FeedbackRepository;
use App\Modules\Perform\Contracts\KpiRepository;
use App\Modules\Perform\Contracts\ObjectiveRepository;
use App\Modules\Perform\Contracts\OneOnOneRepository;
use App\Modules\Perform\Contracts\ReviewRepository;
use App\Modules\Perform\Repositories\EloquentDevelopmentPlanRepository;
use App\Modules\Perform\Repositories\EloquentFeedbackRepository;
use App\Modules\Perform\Repositories\EloquentKpiRepository;
use App\Modules\Perform\Repositories\EloquentObjectiveRepository;
use App\Modules\Perform\Repositories\EloquentOneOnOneRepository;
use App\Modules\Perform\Repositories\EloquentReviewRepository;
use Illuminate\Support\Facades\Gate;

/**
 * Perform: 1:1s, objectives (OKR) with check-ins, KPIs, continuous feedback, review cycles (competencies, rating
 * scales, 360 with anonymous peer/upward groups), development plans. Routes: /api/perform/*.
 * Access follows People: admin (= HR) everything, a manager — people below them, an employee — own items.
 */
final class PerformServiceProvider extends ModuleServiceProvider
{
    /** Review setup (scales, competencies, cycles), 1:1 templates: superadmin, admin. */
    public const string MANAGE = 'perform-manage';

    protected string $prefix = 'perform';

    public function register(): void
    {
        $this->app->bind(OneOnOneRepository::class, EloquentOneOnOneRepository::class);
        $this->app->bind(ObjectiveRepository::class, EloquentObjectiveRepository::class);
        $this->app->bind(KpiRepository::class, EloquentKpiRepository::class);
        $this->app->bind(FeedbackRepository::class, EloquentFeedbackRepository::class);
        $this->app->bind(ReviewRepository::class, EloquentReviewRepository::class);
        $this->app->bind(DevelopmentPlanRepository::class, EloquentDevelopmentPlanRepository::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::define(self::MANAGE, fn (User $user): bool => $this->app->make(PeopleScope::class)->isAdmin($user));
    }
}
