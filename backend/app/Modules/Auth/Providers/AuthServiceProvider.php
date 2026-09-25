<?php

declare(strict_types=1);

namespace App\Modules\Auth\Providers;

use App\Modules\Auth\Contracts\GoogleIdentityProvider;
use App\Modules\Auth\Contracts\UserRepository;
use App\Modules\Auth\Repositories\EloquentUserRepository;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Services\SocialiteGoogleIdentityProvider;
use App\Modules\Core\Support\ModuleServiceProvider;
use Illuminate\Contracts\Foundation\Application;

final class AuthServiceProvider extends ModuleServiceProvider
{
    protected string $prefix = 'auth';

    public function register(): void
    {
        $this->app->bind(UserRepository::class, EloquentUserRepository::class);
        $this->app->bind(GoogleIdentityProvider::class, SocialiteGoogleIdentityProvider::class);
        $this->app->bind(AuthService::class, fn (Application $app): AuthService => new AuthService(
            $app->make(UserRepository::class),
            $app->make('config')->get('auth.superadmin_email'),
        ));
    }
}
