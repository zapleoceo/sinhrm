<?php

declare(strict_types=1);

namespace App\Modules\SafeSpeak\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The anonymous routes have no session, so a validation error must never become a redirect with flashed input:
 * every answer is JSON regardless of the client's Accept header.
 */
final class ForceJson
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
