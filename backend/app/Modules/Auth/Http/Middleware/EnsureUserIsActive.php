<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Middleware;

use App\Models\User;
use App\Modules\Auth\Support\CredentialSession;
use Closure;
use Illuminate\Auth\SessionGuard;
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

        $web = Auth::guard('web');
        if ($user instanceof User && $web instanceof SessionGuard && $web->viaRemember() && $request->hasSession()
            && ! $request->session()->has(CredentialSession::VERSION_KEY)) {
            // A verified remember-cookie is a new credential grant. Capture the loaded actor, never a refreshed version.
            $request->session()->put(CredentialSession::VERSION_KEY, $user->credential_version);
        }
        // Only a durable web credential has the guard's login key. Bearer/actingAs identities have none.
        if ($user instanceof User && $web instanceof SessionGuard && $request->hasSession()
            && $request->session()->has($web->getName())
            && $request->session()->get(CredentialSession::VERSION_KEY) !== $user->credential_version) {
            $web->logoutCurrentDevice();
            $request->session()->invalidate();

            return response()->json(['message' => 'credentials_revoked'], 401);
        }

        return $next($request);
    }
}
