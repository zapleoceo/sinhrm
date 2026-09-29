<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Працювати як": the role picked in the SPA lives in the session (key ACTIVE_ROLE, cleared on logout). On every
 * API request of that session the signed-in user is narrowed to it (User::actAs) BEFORE any gate, policy, module
 * check or scope runs. Bearer tokens have no session and always work with all roles. Appended to the "api" group.
 */
final class ApplyActiveRole
{
    public const string SESSION_KEY = 'active_role';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession()) {
            $role = $request->session()->get(self::SESSION_KEY);
            $user = is_string($role) ? Auth::guard('web')->user() : null;
            if ($user instanceof User) {
                $user->actAs($role);
                if ($user->activeRole() === null) {
                    // No longer assigned (or only one role left): forget it, so a later re-assignment does not
                    // silently narrow the user again.
                    $request->session()->forget(self::SESSION_KEY);
                }
            }
        }

        return $next($request);
    }
}
