<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Providers;

use App\Models\User;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Recruiting\Console\RecruitingDemoCommand;
use App\Modules\Recruiting\Contracts\ApplicationRepository;
use App\Modules\Recruiting\Contracts\CandidateRepository;
use App\Modules\Recruiting\Contracts\ExtensionTokenRepository;
use App\Modules\Recruiting\Contracts\PipelineRepository;
use App\Modules\Recruiting\Contracts\ReportRepository;
use App\Modules\Recruiting\Contracts\TouchpointEvaluations;
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
use App\Modules\Recruiting\Repositories\SanctumExtensionTokenRepository;
use App\Modules\Recruiting\Services\ExtensionTokenService;
use App\Modules\Recruiting\Services\MatchingTouchpointIngestor;
use App\Modules\Recruiting\Services\RecruitingScope;
use App\Modules\Recruiting\Support\NullTouchpointEvaluations;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

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
        $this->app->bind(ExtensionTokenRepository::class, SanctumExtensionTokenRepository::class);
        // Replaced by the Scripts module (script evaluations on timeline items).
        $this->app->bindIf(TouchpointEvaluations::class, NullTouchpointEvaluations::class);
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

        $this->bootExtensionTokens();

        if ($this->app->runningInConsole()) {
            $this->commands([RecruitingDemoCommand::class]);
        }
    }

    /**
     * Bearer tokens are scoped by route, not only by ability middleware: Sanctum's guard would otherwise accept any
     * valid token on every auth:sanctum route. A token authenticates only on /api/clipper/* (and must carry
     * "clipper") or elsewhere with the "full" ability (never issued today) — so the extension token gets 401 on
     * /api/candidates, /api/users, /api/me/extension-token, … Session (cookie) auth is untouched.
     */
    private function bootExtensionTokens(): void
    {
        Sanctum::authenticateAccessTokensUsing(static function (PersonalAccessToken $token, bool $isValid): bool {
            if (! $isValid) {
                return false;
            }

            return request()->is('api/clipper/*') ? $token->can(ExtensionTokenService::ABILITY) : $token->can('full');
        });

        // 30 requests/min per token (per user for a session, per IP as the last resort).
        RateLimiter::for('clipper', static function (Request $request): Limit {
            $user = $request->user();
            $token = $user instanceof User ? $user->currentAccessToken() : null;
            $key = match (true) {
                $token instanceof PersonalAccessToken => 'token:'.$token->id,
                $user instanceof User => 'user:'.$user->id,
                default => 'ip:'.$request->ip(),
            };

            return Limit::perMinute(30)->by('clipper|'.$key);
        });
    }
}
