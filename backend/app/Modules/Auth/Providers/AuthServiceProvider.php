<?php

declare(strict_types=1);

namespace App\Modules\Auth\Providers;

use App\Modules\Auth\Contracts\GoogleIdentityProvider;
use App\Modules\Auth\Contracts\PersonalTokenRepository;
use App\Modules\Auth\Contracts\UserRepository;
use App\Modules\Auth\Repositories\EloquentUserRepository;
use App\Modules\Auth\Repositories\SanctumPersonalTokenRepository;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Services\SocialiteGoogleIdentityProvider;
use App\Modules\Auth\Support\TokenScopes;
use App\Modules\Core\Support\ModuleServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

final class AuthServiceProvider extends ModuleServiceProvider
{
    protected bool $coreModule = true;

    protected string $prefix = 'auth';

    public function register(): void
    {
        $this->app->bind(UserRepository::class, EloquentUserRepository::class);
        $this->app->bind(GoogleIdentityProvider::class, SocialiteGoogleIdentityProvider::class);
        $this->app->bind(PersonalTokenRepository::class, SanctumPersonalTokenRepository::class);
        $this->app->singleton(TokenScopes::class);
        $this->app->bind(AuthService::class, fn (Application $app): AuthService => new AuthService(
            $app->make(UserRepository::class),
            $app->make('config')->get('auth.superadmin_email'),
        ));
    }

    public function boot(): void
    {
        parent::boot();

        // Scoped bearer tokens: each is accepted only on the paths its module registered (TokenScopes).
        $scopes = $this->app->make(TokenScopes::class);
        Sanctum::authenticateAccessTokensUsing(static fn (PersonalAccessToken $token, bool $isValid): bool => $isValid && $scopes->allows($token, request()));
    }
}
