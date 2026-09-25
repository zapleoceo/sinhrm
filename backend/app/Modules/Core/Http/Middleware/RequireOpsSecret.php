<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Guards machine-only endpoints with the X-Ops-Secret header (constant-time comparison).
 *
 * Only FAILED attempts are rate-limited (10/min per IP). A request with the correct secret never
 * touches the cache — so /api/ops/migrate can bootstrap an empty database (no cache table yet).
 * If the limiter itself is unavailable, a wrong secret is still rejected (fail closed).
 */
final class RequireOpsSecret
{
    private const MAX_FAILED_ATTEMPTS = 10;

    private const DECAY_SECONDS = 60;

    public function __construct(private readonly RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('ops.secret');

        if (! is_string($secret) || $secret === '') {
            abort(404);
        }

        $given = $request->header('X-Ops-Secret');
        if (is_string($given) && hash_equals($secret, $given)) {
            return $next($request);
        }

        abort($this->registerFailure('ops:'.$request->ip()) ? 401 : 429);
    }

    /** @return bool false when the caller exceeded the failure limit */
    private function registerFailure(string $key): bool
    {
        try {
            if ($this->limiter->tooManyAttempts($key, self::MAX_FAILED_ATTEMPTS)) {
                return false;
            }
            $this->limiter->hit($key, self::DECAY_SECONDS);
        } catch (Throwable) {
            // Cache backend not ready (fresh DB): still reject the request.
        }

        return true;
    }
}
