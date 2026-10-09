<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Contracts\GoogleIdentityProvider;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Auth\Enums\UserStatus;
use App\Modules\Auth\Exceptions\LoginDenied;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Support\CredentialSession;
use App\Modules\Users\Services\UserAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeGoogleIdentityProvider;
use Tests\TestCase;

final class GoogleCallbackTest extends TestCase
{
    use RefreshDatabase;

    private const string CALLBACK = '/api/auth/google/callback';

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.superadmin_email' => 'owner@example.com']);
    }

    public function test_redirect_sends_the_browser_to_google(): void
    {
        $this->fakeGoogle(FakeGoogleIdentityProvider::returning('x@example.com'));

        $this->get('/api/auth/google/redirect')->assertRedirect('https://accounts.google.test/o/oauth2/auth');
    }

    public function test_first_login_of_superadmin_email_creates_superadmin(): void
    {
        $this->fakeGoogle(FakeGoogleIdentityProvider::returning('Owner@Example.com'));

        $this->get(self::CALLBACK)->assertRedirect('/');

        $user = User::query()->where('email', 'owner@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole(UserRole::Superadmin->value));
        $this->assertSame('g-1', $user->google_id);
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_remember_cookie_lives_fourteen_days_not_the_framework_default(): void
    {
        User::factory()->withRole(UserRole::Recruiter)->create(['email' => 'remember@example.com', 'password' => null]);
        $this->fakeGoogle(FakeGoogleIdentityProvider::returning('remember@example.com', 'g-77'));

        $response = $this->get(self::CALLBACK)->assertRedirect('/');

        $cookie = collect($response->headers->getCookies())->first(fn ($c): bool => str_starts_with($c->getName(), 'remember_web_'));
        $this->assertNotNull($cookie);
        $days = ($cookie->getExpiresTime() - time()) / 86400;
        $this->assertGreaterThan(13.9, $days);
        $this->assertLessThan(14.1, $days);
    }

    public function test_invited_user_is_linked_to_google_account(): void
    {
        $invited = User::factory()->withRole(UserRole::Recruiter)->create(['email' => 'Invited@Example.com', 'password' => null]);
        $this->fakeGoogle(FakeGoogleIdentityProvider::returning('invited@example.com', 'g-42'));

        $this->get(self::CALLBACK)->assertRedirect('/');

        $invited->refresh();
        $this->assertSame('g-42', $invited->google_id);
        $this->assertNotNull($invited->last_login_at);
        $this->assertAuthenticatedAs($invited, 'web');
        $this->assertSame($invited->credential_version, session(CredentialSession::VERSION_KEY));
    }

    public function test_a_new_explicit_google_login_after_unblock_captures_the_current_generation(): void
    {
        $admin = User::factory()->withRole(UserRole::Superadmin)->create();
        $target = User::factory()->create(['email' => 'synthetic-login@example.test']);
        app(UserAdminService::class)->update($admin, $target, null, UserStatus::Blocked);
        app(UserAdminService::class)->update($admin, $target, null, UserStatus::Active);
        $this->fakeGoogle(FakeGoogleIdentityProvider::returning('synthetic-login@example.test'));
        $this->get(self::CALLBACK)->assertRedirect('/');
        $this->assertAuthenticatedAs($target, 'web');
        $this->assertSame(1, session(CredentialSession::VERSION_KEY));
    }

    public function test_a_stale_google_grant_after_block_and_unblock_never_runs_the_login_callback(): void
    {
        $admin = User::factory()->withRole(UserRole::Superadmin)->create();
        $target = User::factory()->create();
        $stale = User::query()->findOrFail($target->id);
        app(UserAdminService::class)->update($admin, $target, null, UserStatus::Blocked);
        app(UserAdminService::class)->update($admin, $target, null, UserStatus::Active);
        $this->expectException(LoginDenied::class);
        $this->expectExceptionMessage('oauth_failed');
        app(AuthService::class)->grantSession($stale, function (User $current): void {
            $this->fail('Revoked OAuth credentials must not run the login callback');
        });
    }

    public function test_a_new_sign_in_never_inherits_the_previous_work_as_choice(): void
    {
        User::factory()->withRole(UserRole::Recruiter)->create(['email' => 'next@example.com', 'password' => null]);
        $this->fakeGoogle(FakeGoogleIdentityProvider::returning('next@example.com', 'g-7'));

        $this->withSession(['active_role' => 'superadmin'])->get(self::CALLBACK)->assertRedirect('/');

        $this->assertFalse(session()->has('active_role'));
    }

    public function test_known_google_id_logs_in_even_after_email_change(): void
    {
        $user = User::factory()->create(['email' => 'new@example.com', 'google_id' => 'g-7']);
        $this->fakeGoogle(FakeGoogleIdentityProvider::returning('old@example.com', 'g-7'));

        $this->get(self::CALLBACK)->assertRedirect('/');
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_uninvited_email_is_denied(): void
    {
        $this->fakeGoogle(FakeGoogleIdentityProvider::returning('stranger@example.com'));

        $this->get(self::CALLBACK)->assertRedirect('/login?error=not_invited');
        $this->assertGuest('web');
        $this->assertDatabaseMissing('users', ['email' => 'stranger@example.com']);
    }

    public function test_blocked_user_is_denied(): void
    {
        User::factory()->blocked()->create(['email' => 'blocked@example.com']);
        $this->fakeGoogle(FakeGoogleIdentityProvider::returning('blocked@example.com'));

        $this->get(self::CALLBACK)->assertRedirect('/login?error=blocked');
        $this->assertGuest('web');
    }

    public function test_unverified_google_email_is_denied(): void
    {
        $this->fakeGoogle(FakeGoogleIdentityProvider::returning('owner@example.com', verified: false));

        $this->get(self::CALLBACK)->assertRedirect('/login?error=email_unverified');
        $this->assertGuest('web');
    }

    public function test_oauth_failure_redirects_with_generic_code(): void
    {
        $this->fakeGoogle(FakeGoogleIdentityProvider::failing());

        $this->get(self::CALLBACK)->assertRedirect('/login?error=oauth_failed');
        $this->assertGuest('web');
    }

    public function test_consent_denied_redirects_with_generic_code(): void
    {
        $this->fakeGoogle(FakeGoogleIdentityProvider::returning('owner@example.com'));

        $this->get(self::CALLBACK.'?error=access_denied')->assertRedirect('/login?error=oauth_failed');
        $this->assertDatabaseCount('users', 0);
    }

    private function fakeGoogle(GoogleIdentityProvider $fake): void
    {
        $this->app->instance(GoogleIdentityProvider::class, $fake);
    }
}
