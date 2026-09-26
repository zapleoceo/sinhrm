<?php

declare(strict_types=1);

namespace App\Modules\SafeSpeak\Providers;

use App\Models\User;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\SafeSpeak\Contracts\SafeSpeakRepository;
use App\Modules\SafeSpeak\Http\Controllers\PublicReportController;
use App\Modules\SafeSpeak\Http\Middleware\ForceJson;
use App\Modules\SafeSpeak\Repositories\EloquentSafeSpeakRepository;
use App\Modules\SafeSpeak\Services\SafeSpeakService;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * Safe Speak: anonymous reports with an access code, handler inbox. Routes: /api/safe-speak/* (handlers, session)
 * and /api/safe-speak/public/* (anonymous, no session — routes.public.php).
 */
final class SafeSpeakServiceProvider extends ModuleServiceProvider
{
    protected string $moduleIcon = 'shield';

    protected string $moduleGroup = 'services';

    /** Read and answer reports: active superadmin/admin with users.safe_speak_handler = true. */
    public const string HANDLE = 'safe-speak-handle';

    protected string $prefix = 'safe-speak';

    public function register(): void
    {
        $this->app->bind(SafeSpeakRepository::class, EloquentSafeSpeakRepository::class);
        // HMAC key for access codes and client buckets.
        $key = static fn (Application $app): string => (string) $app->make('config')->get('app.key');
        $this->app->when(SafeSpeakService::class)->needs('$appKey')->give($key);
        $this->app->when(PublicReportController::class)->needs('$appKey')->give($key);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::define(self::HANDLE, fn (User $user): bool => $this->app->make(SafeSpeakService::class)->isHandler($user));

        if (! $this->app->routesAreCached()) {
            Route::prefix('api/safe-speak/public')->middleware([ForceJson::class, ...$this->accessMiddleware()])->group($this->moduleDir().'/routes.public.php');
        }
    }
}
