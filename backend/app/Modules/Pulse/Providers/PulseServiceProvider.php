<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Providers;

use App\Models\User;
use App\Modules\Core\Contracts\NavBadgeProvider;
use App\Modules\Core\Contracts\ScheduledJob;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\People\Events\EmployeeTerminated;
use App\Modules\People\Services\PeopleScope;
use App\Modules\Pulse\Contracts\MoodRepository;
use App\Modules\Pulse\Contracts\ResponseRepository;
use App\Modules\Pulse\Contracts\SurveyRepository;
use App\Modules\Pulse\Contracts\WaveMemberRepository;
use App\Modules\Pulse\Listeners\StartExitSurvey;
use App\Modules\Pulse\Repositories\EloquentMoodRepository;
use App\Modules\Pulse\Repositories\EloquentResponseRepository;
use App\Modules\Pulse\Repositories\EloquentSurveyRepository;
use App\Modules\Pulse\Repositories\EloquentWaveMemberRepository;
use App\Modules\Pulse\Services\PulseNavBadges;
use App\Modules\Pulse\Services\PulseTickJob;
use App\Modules\Pulse\Support\RespondentHash;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

/**
 * Pulse: surveys with waves (schedule, audience, anonymity with a minimum group), eNPS, wave comparison, lifecycle
 * surveys (30/90 days after hire, exit), mood check-ins with team trends and manager alerts; "pulse.tick".
 * Routes: /api/pulse/*.
 */
final class PulseServiceProvider extends ModuleServiceProvider
{
    protected string $moduleIcon = 'poll';

    protected string $moduleGroup = 'perform';

    /** The survey builder, waves, identified responses, mood settings: superadmin, admin. */
    public const string MANAGE = 'pulse-manage';

    protected string $prefix = 'pulse';

    public function register(): void
    {
        $this->app->tag([PulseNavBadges::class], NavBadgeProvider::class);
        $this->app->bind(SurveyRepository::class, EloquentSurveyRepository::class);
        $this->app->bind(ResponseRepository::class, EloquentResponseRepository::class);
        $this->app->bind(MoodRepository::class, EloquentMoodRepository::class);
        $this->app->bind(WaveMemberRepository::class, EloquentWaveMemberRepository::class);
        $this->app->bind(RespondentHash::class, static fn (): RespondentHash => new RespondentHash(
            (string) config('app.key'),
            array_values(array_map('strval', (array) config('app.previous_keys', []))),
        ));
        $this->app->tag([PulseTickJob::class], ScheduledJob::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::define(self::MANAGE, fn (User $user): bool => $this->app->make(PeopleScope::class)->isAdmin($user));
        Event::listen(EmployeeTerminated::class, StartExitSurvey::class);
    }
}
