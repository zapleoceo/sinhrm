<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Providers;

use App\Models\User;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Recruiting\Console\RecruitingDemoCommand;
use App\Modules\Recruiting\Contracts\ApplicationRepository;
use App\Modules\Recruiting\Contracts\CandidateRepository;
use App\Modules\Recruiting\Contracts\PipelineRepository;
use App\Modules\Recruiting\Contracts\ReportRepository;
use App\Modules\Recruiting\Contracts\TouchpointIngestor;
use App\Modules\Recruiting\Contracts\TouchpointRepository;
use App\Modules\Recruiting\Contracts\VacancyRepository;
use App\Modules\Recruiting\Events\TouchpointRecorded;
use App\Modules\Recruiting\Listeners\UpdateLastTouch;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\Touchpoint;
use App\Modules\Recruiting\Models\Vacancy;
use App\Modules\Recruiting\Policies\ApplicationPolicy;
use App\Modules\Recruiting\Policies\CandidatePolicy;
use App\Modules\Recruiting\Policies\TouchpointPolicy;
use App\Modules\Recruiting\Policies\VacancyPolicy;
use App\Modules\Recruiting\Repositories\EloquentApplicationRepository;
use App\Modules\Recruiting\Repositories\EloquentCandidateRepository;
use App\Modules\Recruiting\Repositories\EloquentPipelineRepository;
use App\Modules\Recruiting\Repositories\EloquentTouchpointRepository;
use App\Modules\Recruiting\Repositories\EloquentVacancyRepository;
use App\Modules\Recruiting\Repositories\QueryReportRepository;
use App\Modules\Recruiting\Services\MatchingTouchpointIngestor;
use App\Modules\Recruiting\Services\RecruitingScope;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

/** Routes live at the /api root (vacancies, candidates, applications, inbox, recruiting, reports, pipelines). */
final class RecruitingServiceProvider extends ModuleServiceProvider
{
    /** Write access at all (superadmin, admin, recruiter); entity policies add the branch scope. */
    public const string WRITE = 'recruiting-write';

    /** Pipelines and reject reasons dictionary: superadmin, admin. */
    public const string MANAGE = 'recruiting-manage';

    public function register(): void
    {
        $this->app->bind(PipelineRepository::class, EloquentPipelineRepository::class);
        $this->app->bind(VacancyRepository::class, EloquentVacancyRepository::class);
        $this->app->bind(CandidateRepository::class, EloquentCandidateRepository::class);
        $this->app->bind(ApplicationRepository::class, EloquentApplicationRepository::class);
        $this->app->bind(TouchpointRepository::class, EloquentTouchpointRepository::class);
        $this->app->bind(ReportRepository::class, QueryReportRepository::class);
        $this->app->bind(TouchpointIngestor::class, MatchingTouchpointIngestor::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Vacancy::class, VacancyPolicy::class);
        Gate::policy(Candidate::class, CandidatePolicy::class);
        Gate::policy(Application::class, ApplicationPolicy::class);
        Gate::policy(Touchpoint::class, TouchpointPolicy::class);
        Gate::define(self::WRITE, fn (User $user): bool => $this->app->make(RecruitingScope::class)->canWrite($user));
        Gate::define(self::MANAGE, fn (User $user): bool => $this->app->make(RecruitingScope::class)->canManage($user));

        Event::listen(TouchpointRecorded::class, UpdateLastTouch::class);

        if ($this->app->runningInConsole()) {
            $this->commands([RecruitingDemoCommand::class]);
        }
    }
}
