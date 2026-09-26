<?php

declare(strict_types=1);

namespace App\Modules\Observability\Providers;

use App\Modules\Core\Contracts\ScheduledJob;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Observability\Services\ErrorLogPruneJob;
use App\Modules\Observability\Services\ErrorRecorder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * In-app error log (docs/architecture/observability.md): unhandled server exceptions and SPA errors grouped in
 * error_events, the superadmin screen, 30-day retention via the cron job "errors.prune". Routes: /api/errors/*.
 */
final class ObservabilityServiceProvider extends ModuleServiceProvider
{
    public const string CLIENT_LIMITER = 'client-errors';

    /** Client reports per user per minute: a render loop must not flood the table. */
    public const int CLIENT_PER_MINUTE = 10;

    protected string $prefix = 'errors';

    protected string $moduleIcon = 'bug_report';

    /**
     * Core (docs/modules/modules-access.md): POST /api/errors/client is infrastructure every signed-in user must reach,
     * and the log itself is already superadmin only (can:manage-integrations), so there is nothing to switch off.
     */
    protected bool $coreModule = true;

    public function register(): void
    {
        $this->app->singleton(ErrorRecorder::class);
        $this->app->tag([ErrorLogPruneJob::class], ScheduledJob::class);
    }

    public function boot(): void
    {
        parent::boot();

        RateLimiter::for(self::CLIENT_LIMITER, static fn (Request $request): Limit => Limit::perMinute(self::CLIENT_PER_MINUTE)
            ->by('client-errors:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
