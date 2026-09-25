<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Contracts\GoogleIdentityProvider;
use App\Modules\Auth\Enums\LoginDenial;
use App\Modules\Auth\Exceptions\GoogleAuthFailed;
use App\Modules\Auth\Exceptions\LoginDenied;
use App\Modules\Auth\Services\AuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

/**
 * Browser OAuth flow (routes.web.php → 'web' middleware, so the session carries the OAuth state).
 * Redirects back to the SPA use relative URLs: the API is served through the SPA origin (Vercel rewrite),
 * so an absolute URL built from the API host would leave the SPA domain.
 */
final class GoogleAuthController
{
    public function __construct(
        private readonly GoogleIdentityProvider $google,
        private readonly AuthService $auth,
    ) {}

    public function redirect(): SymfonyRedirect
    {
        return $this->google->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($request->query->has('error')) {
            return $this->fail(LoginDenial::OauthFailed, 'consent_denied');
        }

        try {
            $user = $this->auth->handleGoogle($this->google->profile());
        } catch (GoogleAuthFailed $e) {
            return $this->fail(LoginDenial::OauthFailed, $e->getMessage());
        } catch (LoginDenied $e) {
            return $this->fail($e->reason);
        }

        Auth::guard('web')->login($user, remember: true);
        $request->session()->regenerate();

        return new RedirectResponse('/');
    }

    private function fail(LoginDenial $reason, ?string $detail = null): RedirectResponse
    {
        // No e-mail, tokens or codes in logs/URL — only the reason code.
        Log::warning('auth.google.denied', array_filter(['reason' => $reason->value, 'detail' => $detail]));

        return new RedirectResponse('/login?error='.$reason->value);
    }
}
