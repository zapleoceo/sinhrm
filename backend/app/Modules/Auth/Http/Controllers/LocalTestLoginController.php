<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Contracts\UserRepository;
use App\Modules\Auth\Http\Middleware\ApplyActiveRole;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Support\CredentialSession;
use App\Modules\Auth\Support\LocalTestLoginGuard;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/** Available only to the loopback PHP development server; never a deployment login method. */
final class LocalTestLoginController
{
    public function __invoke(Request $request, UserRepository $users, AuthService $auth): Response
    {
        if (! LocalTestLoginGuard::allows(
            app()->environment(), PHP_SAPI, $request->ip(), $request->getHost(),
            (bool) config('auth.local_test_login_enabled'),
            (string) config('auth.local_test_login_secret'),
            (string) $request->input('secret', ''),
        )) {
            abort(404);
        }

        $user = $users->findByEmail((string) config('auth.local_test_login_email'));
        if ($user === null || ! $user->isActive()) {
            abort(404);
        }

        $auth->grantSession($user, function (User $current) use ($request): void {
            Auth::guard('web')->login($current);
            $request->session()->regenerate();
            $request->session()->put(CredentialSession::VERSION_KEY, $current->credential_version);
            $request->session()->forget(ApplyActiveRole::SESSION_KEY);
        });

        return response()->noContent();
    }
}
