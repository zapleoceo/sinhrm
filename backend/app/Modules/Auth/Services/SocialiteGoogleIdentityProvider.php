<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Contracts\GoogleIdentityProvider;
use App\Modules\Auth\DTO\GoogleProfile;
use App\Modules\Auth\Exceptions\GoogleAuthFailed;
use Laravel\Socialite\AbstractUser;
use Laravel\Socialite\Contracts\Factory as Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Throwable;

final class SocialiteGoogleIdentityProvider implements GoogleIdentityProvider
{
    private const array SCOPES = ['openid', 'email', 'profile'];

    public function __construct(private readonly Socialite $socialite) {}

    public function redirect(): RedirectResponse
    {
        $driver = $this->socialite->driver('google');
        if ($driver instanceof AbstractProvider) {
            $driver->setScopes(self::SCOPES);
        }

        return $driver->redirect();
    }

    public function profile(): GoogleProfile
    {
        try {
            $user = $this->socialite->driver('google')->user();
        } catch (Throwable $e) {
            // Only the exception class: HTTP client error messages may echo request data.
            throw new GoogleAuthFailed($e::class, previous: $e);
        }

        $raw = $user instanceof AbstractUser ? $user->getRaw() : [];

        return new GoogleProfile(
            googleId: (string) $user->getId(),
            email: (string) $user->getEmail(),
            name: (string) ($user->getName() ?: $user->getEmail()),
            avatarUrl: $user->getAvatar(),
            emailVerified: filter_var($raw['email_verified'] ?? false, FILTER_VALIDATE_BOOL),
        );
    }
}
