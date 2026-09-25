<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Auth\Contracts\GoogleIdentityProvider;
use App\Modules\Auth\DTO\GoogleProfile;
use App\Modules\Auth\Exceptions\GoogleAuthFailed;
use Symfony\Component\HttpFoundation\RedirectResponse;

/** Test double: returns a preset profile or fails like an invalid OAuth state. */
final class FakeGoogleIdentityProvider implements GoogleIdentityProvider
{
    public function __construct(private readonly ?GoogleProfile $profile) {}

    public static function returning(string $email, string $googleId = 'g-1', bool $verified = true): self
    {
        return new self(new GoogleProfile($googleId, $email, 'Test User', 'https://example.com/a.png', $verified));
    }

    public static function failing(): self
    {
        return new self(null);
    }

    public function redirect(): RedirectResponse
    {
        return new RedirectResponse('https://accounts.google.test/o/oauth2/auth');
    }

    public function profile(): GoogleProfile
    {
        return $this->profile ?? throw new GoogleAuthFailed('InvalidStateException');
    }
}
