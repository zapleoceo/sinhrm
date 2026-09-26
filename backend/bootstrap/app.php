<?php

declare(strict_types=1);

use App\Modules\Core\Http\Middleware\SecurityHeaders;
use App\Modules\Integrations\Support\SecretScrubber;
use App\Modules\Observability\Services\ErrorRecorder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA cookie auth: the SPA is same-origin with the API (Vercel rewrite).
        $middleware->statefulApi();
        // API-only app: there is no "login" route to redirect guests to — answer 401 JSON instead of a 500.
        $middleware->redirectGuestsTo(fn (Request $request): ?string => null);
        // X-Frame-Options, nosniff, CSP, HSTS, … on every API response (docs/architecture/observability.md).
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // In-app error log: every reported (= unhandled, not 4xx) exception is grouped into error_events.
        // Registered first and returns null, so the reporters below still run; ErrorRecorder never throws.
        $exceptions->report(function (Throwable $e): void {
            try {
                app(ErrorRecorder::class)->recordException($e);
            } catch (Throwable) {
                // never let the error log break error reporting
            }
        });
        // Secrets never reach the log: if an exception message (or a previous one) contains a token, Bearer value
        // or a secret decrypted in this request, log a redacted line without the trace and stop default reporting.
        $exceptions->report(function (Throwable $e): ?bool {
            $scrubber = app(SecretScrubber::class);
            for ($current = $e; $current !== null; $current = $current->getPrevious()) {
                if ($scrubber->scrub($current->getMessage()) !== $current->getMessage()) {
                    Log::error($scrubber->scrub($e->getMessage()), ['exception' => $e::class, 'redacted' => true]);

                    return false;
                }
            }

            return null;
        });
    })->create();
