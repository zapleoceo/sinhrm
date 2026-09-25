<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Guards machine-only endpoints with the X-Ops-Secret header (constant-time comparison). */
final class RequireOpsSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('ops.secret');

        if (! is_string($secret) || $secret === '') {
            abort(404);
        }

        $given = $request->header('X-Ops-Secret');
        if (! is_string($given) || ! hash_equals($secret, $given)) {
            abort(401);
        }

        return $next($request);
    }
}
