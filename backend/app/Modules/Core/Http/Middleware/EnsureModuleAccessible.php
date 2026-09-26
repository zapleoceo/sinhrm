<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Middleware;

use App\Models\User;
use App\Modules\Core\Services\ModuleAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Put by ModuleServiceProvider on every route of a non-core module (EnsureModuleAccessible::class.':<key>').
 * Switched off: 403 "module_disabled" (404 for anonymous calls such as provider webhooks, so nothing leaks);
 * role not allowed: 403 "module_forbidden". Guests of an enabled module fall through to the route's own auth.
 */
final class EnsureModuleAccessible
{
    public function __construct(private readonly ModuleAccess $access) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        $user = $request->user();

        if (! $this->access->enabled($module)) {
            return $user instanceof User
                ? response()->json(['message' => 'module_disabled', 'module' => $module], 403)
                : response()->json(['message' => 'Not Found'], 404);
        }

        if ($user instanceof User && ! $this->access->allows($user, $module)) {
            return response()->json(['message' => 'module_forbidden', 'module' => $module], 403);
        }

        return $next($request);
    }
}
