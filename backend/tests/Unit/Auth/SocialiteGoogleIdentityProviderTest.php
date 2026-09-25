<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Modules\Auth\Contracts\GoogleIdentityProvider;
use App\Modules\Auth\Exceptions\GoogleAuthFailed;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

/** Boots the app (no database) to check the Socialite adapter and its container binding. */
final class SocialiteGoogleIdentityProviderTest extends TestCase
{
    public function test_maps_socialite_user_to_profile(): void
    {
        $user = (new SocialiteUser)
            ->setRaw(['email_verified' => true])
            ->map(['id' => '123', 'email' => 'Person@Example.com', 'name' => 'Person', 'avatar' => 'https://example.com/p.png']);
        Socialite::fake('google', $user);

        $profile = $this->app->make(GoogleIdentityProvider::class)->profile();

        $this->assertSame('123', $profile->googleId);
        $this->assertSame('person@example.com', $profile->email);
        $this->assertSame('Person', $profile->name);
        $this->assertSame('https://example.com/p.png', $profile->avatarUrl);
        $this->assertTrue($profile->emailVerified);
    }

    public function test_missing_verification_flag_means_unverified(): void
    {
        Socialite::fake('google', (new SocialiteUser)->map(['id' => '1', 'email' => 'a@example.com', 'name' => null]));

        $profile = $this->app->make(GoogleIdentityProvider::class)->profile();

        $this->assertFalse($profile->emailVerified);
        $this->assertSame('a@example.com', $profile->name);
    }

    public function test_socialite_errors_become_google_auth_failed_without_details(): void
    {
        Socialite::fake('google', fn () => throw new InvalidStateException('state mismatch: secret-ish'));

        try {
            $this->app->make(GoogleIdentityProvider::class)->profile();
            $this->fail('GoogleAuthFailed expected');
        } catch (GoogleAuthFailed $e) {
            $this->assertSame(InvalidStateException::class, $e->getMessage());
        }
    }

    public function test_redirect_goes_to_provider(): void
    {
        Socialite::fake('google');

        $this->assertStringStartsWith('https://socialite.fake/google', (string) $this->app->make(GoogleIdentityProvider::class)->redirect()->getTargetUrl());
    }
}
