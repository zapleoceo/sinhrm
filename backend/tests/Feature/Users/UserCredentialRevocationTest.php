<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Auth\Enums\UserStatus;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Support\CredentialSession;
use App\Modules\Users\Exceptions\UserAdminException;
use App\Modules\Users\Services\UserAdminService;
use Illuminate\Auth\SessionGuard;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class UserCredentialRevocationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'database', 'session.connection' => null]);
        $this->admin = User::factory()->withRole(UserRole::Superadmin)->create();
    }

    public function test_restoring_a_legacy_blocked_account_revokes_its_pre_upgrade_credentials(): void
    {
        $target = User::factory()->blocked()->withRole(UserRole::Viewer)->create(['remember_token' => 'synthetic-legacy-remember']);
        $other = User::factory()->create(['remember_token' => 'synthetic-healthy-remember']);
        $token = $target->createToken('extension')->plainTextToken;
        $target->createToken('mcp');
        $otherToken = $other->createToken('extension')->plainTextToken;
        $this->sessionFor($target, str_repeat('l', 40), legacy: true);
        $this->sessionFor($other, str_repeat('o', 40));
        $name = $this->sessionGuard()->getRecallerName();
        $cookie = $target->id.'|'.$target->getRememberToken().'|'.$target->getAuthPassword();

        app(UserAdminService::class)->update($this->admin, $target, null, UserStatus::Active);

        $this->assertSame(1, $target->refresh()->credential_version);
        $this->assertNull($target->getRawOriginal('remember_token'));
        $this->assertSame('', $target->getRememberToken());
        $this->assertSame(0, PersonalAccessToken::query()->whereMorphedTo('tokenable', $target)->count());
        $this->bearer($token)->assertUnauthorized();
        $this->remembered($name, $cookie)->assertUnauthorized();
        $this->browser(str_repeat('l', 40))->assertUnauthorized();
        $this->bearer($otherToken)->assertOk()->assertJsonPath('id', $other->id);
        $this->browser(str_repeat('o', 40))->assertOk()->assertJsonPath('id', $other->id);
        $this->assertSame('synthetic-healthy-remember', $other->refresh()->getRememberToken());
        $this->assertSame(0, $other->credential_version);
    }

    public function test_block_revokes_every_session_and_token_name_without_touching_another_user(): void
    {
        $target = User::factory()->withRole(UserRole::Viewer)->create();
        $other = User::factory()->withRole(UserRole::Viewer)->create();
        $this->sessionFor($target, str_repeat('a', 40));
        $this->sessionFor($target, str_repeat('b', 40)); // A different device never requests while blocked.
        $this->sessionFor($other, str_repeat('c', 40));
        $tokens = [$target->createToken('extension')->plainTextToken, $target->createToken('mcp')->plainTextToken];
        $otherToken = $other->createToken('extension')->plainTextToken;

        $this->bearer($tokens[0])->assertOk()->assertJsonPath('id', $target->id);
        $this->browser(str_repeat('a', 40))->assertOk()->assertJsonPath('id', $target->id);
        $this->resetClient();

        $this->actingAs($this->admin)->patchJson('/api/users/'.$target->id, ['status' => 'blocked'])
            ->assertOk()->assertJsonPath('data.status', 'blocked');
        $this->assertSame(0, DB::table('sessions')->where('user_id', $target->id)->count());
        $this->assertSame(0, PersonalAccessToken::query()->whereMorphedTo('tokenable', $target)->count());
        $this->assertDatabaseHas('sessions', ['id' => str_repeat('c', 40), 'user_id' => $other->id]);
        $this->assertSame(1, PersonalAccessToken::query()->whereMorphedTo('tokenable', $other)->count());

        foreach ($tokens as $token) {
            $this->bearer($token)->assertUnauthorized();
        }
        $this->browser(str_repeat('a', 40))->assertUnauthorized();

        $this->resetClient();
        $this->actingAs($this->admin)->patchJson('/api/users/'.$target->id, ['status' => 'active'])->assertOk();
        foreach ($tokens as $token) {
            $this->bearer($token)->assertUnauthorized();
        }
        $this->browser(str_repeat('a', 40))->assertUnauthorized();
        $this->browser(str_repeat('b', 40))->assertUnauthorized();
        $this->browser(str_repeat('c', 40))->assertOk()->assertJsonPath('id', $other->id);
        $this->bearer($otherToken)->assertOk()->assertJsonPath('id', $other->id);
    }

    public function test_remember_cookie_cannot_reauthenticate_after_block_and_unblock(): void
    {
        $target = User::factory()->withRole(UserRole::Viewer)->create();
        $other = User::factory()->create();
        $target->setRememberToken('synthetic-target-remember');
        $target->save();
        $other->setRememberToken('synthetic-other-remember');
        $other->save();
        $name = $this->sessionGuard()->getRecallerName();
        $cookie = $target->id.'|'.$target->getRememberToken().'|'.$target->getAuthPassword();

        $this->remembered($name, $cookie)->assertOk()->assertJsonPath('id', $target->id);
        $this->resetClient();

        $this->actingAs($this->admin)->patchJson('/api/users/'.$target->id, ['status' => 'blocked'])->assertOk();
        $this->assertNull($target->refresh()->getRawOriginal('remember_token'));
        $this->assertSame('', $target->getRememberToken());
        $this->assertSame('synthetic-other-remember', $other->refresh()->getRememberToken());
        $this->remembered($name, $cookie)->assertUnauthorized();

        $this->resetClient();
        $this->actingAs($this->admin)->patchJson('/api/users/'.$target->id, ['status' => 'active'])->assertOk();
        $this->remembered($name, $cookie)->assertUnauthorized();
    }

    public function test_partial_revocation_failure_rolls_back_status_sessions_and_tokens(): void
    {
        $target = User::factory()->withRole(UserRole::Viewer)->create();
        $this->sessionFor($target, str_repeat('d', 40));
        $target->createToken('extension');
        $target->setRememberToken('synthetic-original-remember');
        $target->save();
        DB::listen(static function (QueryExecuted $query): void {
            if (str_starts_with(strtolower($query->sql), 'delete') && str_contains($query->sql, 'personal_access_tokens')) {
                // Throw after the delete executed: both this delete and the previous session delete must roll back.
                throw new RuntimeException('synthetic-revocation-failure');
            }
        });
        try {
            app(UserAdminService::class)->update($this->admin, $target, null, UserStatus::Blocked);
            $this->fail('Revocation failure expected');
        } catch (RuntimeException $error) {
            $this->assertSame('synthetic-revocation-failure', $error->getMessage());
        }

        $this->assertSame(UserStatus::Active, $target->refresh()->status);
        $this->assertDatabaseHas('sessions', ['id' => str_repeat('d', 40), 'user_id' => $target->id]);
        $this->assertSame(1, PersonalAccessToken::query()->whereMorphedTo('tokenable', $target)->count());
        $this->assertSame('synthetic-original-remember', $target->refresh()->getRememberToken());
    }

    public function test_repeated_block_is_safe_and_revokes_credentials_added_since_the_first_block(): void
    {
        $target = User::factory()->withRole(UserRole::Viewer)->create();
        $this->actingAs($this->admin)->patchJson('/api/users/'.$target->id, ['status' => 'blocked'])->assertOk();
        $this->sessionFor($target, str_repeat('e', 40));
        $target->createToken('synthetic-new-kind');
        $this->actingAs($this->admin)->patchJson('/api/users/'.$target->id, ['status' => 'blocked'])->assertOk();
        $this->actingAs($this->admin)->patchJson('/api/users/'.$target->id, ['status' => 'blocked'])->assertOk();
        $this->assertSame(0, DB::table('sessions')->where('user_id', $target->id)->count());
        $this->assertSame(0, PersonalAccessToken::query()->whereMorphedTo('tokenable', $target)->count());
    }

    public function test_a_separate_session_database_fails_closed_without_changing_status_or_credentials(): void
    {
        $target = User::factory()->withRole(UserRole::Viewer)->create();
        $this->sessionFor($target, str_repeat('g', 40));
        $target->createToken('extension');
        config(['session.connection' => 'synthetic-other-connection']);
        try {
            app(UserAdminService::class)->update($this->admin, $target, null, UserStatus::Blocked);
            $this->fail('Separate session database must not report successful revocation');
        } catch (LogicException $error) {
            $this->assertStringContainsString('sessions on the users database connection', $error->getMessage());
        }
        $this->assertSame(UserStatus::Active, $target->refresh()->status);
        $this->assertDatabaseHas('sessions', ['id' => str_repeat('g', 40), 'user_id' => $target->id]);
        $this->assertSame(1, PersonalAccessToken::query()->whereMorphedTo('tokenable', $target)->count());
    }

    public function test_last_superadmin_guard_uses_current_locked_status_instead_of_a_stale_target(): void
    {
        $target = User::factory()->blocked()->withRole(UserRole::Superadmin)->create();
        $this->admin->forceFill(['status' => UserStatus::Blocked])->save();
        User::query()->whereKey($target->id)->update(['status' => 'active']);
        $this->expectException(UserAdminException::class);
        $this->expectExceptionMessage('last_superadmin');

        app(UserAdminService::class)->update($this->admin, $target, null, UserStatus::Blocked);
    }

    public function test_self_block_does_not_revoke_the_acting_admin_credentials(): void
    {
        $this->sessionFor($this->admin, str_repeat('f', 40));
        $this->admin->createToken('extension');
        $this->actingAs($this->admin)->patchJson('/api/users/'.$this->admin->id, ['status' => 'blocked'])
            ->assertUnprocessable()->assertJsonPath('code', 'self_change_forbidden');
        $this->assertDatabaseHas('sessions', ['id' => str_repeat('f', 40), 'user_id' => $this->admin->id]);
        $this->assertSame(1, PersonalAccessToken::query()->whereMorphedTo('tokenable', $this->admin)->count());
        $this->assertSame(UserStatus::Active, $this->admin->refresh()->status);
    }

    public function test_a_session_granted_before_block_cannot_be_resurrected_by_a_late_database_write(): void
    {
        $target = User::factory()->withRole(UserRole::Viewer)->create();
        $captured = null;
        app(AuthService::class)->grantSession($target, static function (User $current) use (&$captured): void {
            $captured = $current->credential_version;
        });
        $this->assertSame(0, $captured);
        app(UserAdminService::class)->update($this->admin, $target, null, UserStatus::Blocked);
        $this->sessionFor($target, str_repeat('h', 40), capturedVersion: $captured);
        $this->browser(str_repeat('h', 40))->assertForbidden()->assertJsonPath('message', 'blocked');
        app(UserAdminService::class)->update($this->admin, $target, null, UserStatus::Active);
        // Simulate middleware persisting the already-granted session after block AND unblock committed.
        $this->sessionFor($target, str_repeat('i', 40), capturedVersion: $captured);
        $this->browser(str_repeat('i', 40))->assertUnauthorized()->assertJsonPath('message', 'credentials_revoked');
        $this->assertSame(1, $target->refresh()->credential_version);
    }

    public function test_legacy_durable_sessions_without_a_generation_stamp_require_a_new_login(): void
    {
        $target = User::factory()->withRole(UserRole::Viewer)->create();
        $this->sessionFor($target, str_repeat('j', 40), legacy: true);
        $this->browser(str_repeat('j', 40))->assertUnauthorized()->assertJsonPath('message', 'credentials_revoked');
    }

    private function sessionGuard(): SessionGuard
    {
        $guard = Auth::guard('web');
        $this->assertInstanceOf(SessionGuard::class, $guard);

        return $guard;
    }

    private function sessionFor(User $user, string $id, ?int $capturedVersion = null, bool $legacy = false): void
    {
        $payload = [$this->sessionGuard()->getName() => $user->id, '_token' => 'synthetic-csrf'];
        if (! $legacy) {
            $payload[CredentialSession::VERSION_KEY] = $capturedVersion ?? $user->credential_version;
        }
        DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $user->id, 'last_activity' => time(),
            'payload' => base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
        ]);
    }

    private function resetClient(): void
    {
        $this->defaultCookies = [];
        $this->defaultHeaders = [];
        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
    }

    /** @return TestResponse<Response> */
    private function bearer(string $token): TestResponse
    {
        $this->resetClient();

        return $this->withToken($token)->getJson('/api/auth/me');
    }

    /** @return TestResponse<Response> */
    private function browser(string $sessionId): TestResponse
    {
        $this->resetClient();

        return $this->withHeader('Origin', 'http://localhost')
            ->withCookie((string) config('session.cookie'), $sessionId)->withCredentials()->getJson('/api/auth/me');
    }

    /** @return TestResponse<Response> */
    private function remembered(string $name, string $cookie): TestResponse
    {
        $this->resetClient();

        return $this->withHeader('Origin', 'http://localhost')->withCookie($name, $cookie)->withCredentials()->getJson('/api/auth/me');
    }
}
