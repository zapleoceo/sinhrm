<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** A user blocked after signing in loses access on the next request (session is dropped). */
final class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->isActive()) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
            }

            return response()->json(['message' => 'blocked'], 403);
        }

        return $next($request);
    }
}
