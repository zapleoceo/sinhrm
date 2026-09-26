<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MeTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_guest_without_accept_json_header_gets_401_not_500(): void
    {
        // Regression: a plain browser/curl request (no Accept: application/json) used to hit
        // "Route [login] not defined" → 500.
        $this->get('/api/auth/me')->assertUnauthorized();
    }

    public function test_me_returns_profile_and_roles(): void
    {
        $user = User::factory()->withRole(UserRole::Viewer)->create(['locale' => 'en']);

        $this->actingAs($user)->getJson('/api/auth/me')
            ->assertOk()
            ->assertExactJson([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar_url' => null,
                'locale' => 'en',
                'roles' => ['viewer'],
                'status' => 'active',
                // Every module a viewer can use today; Ai and MailAgent are superadmin-only (modules-access.md).
                'modules' => [
                    'core', 'auth', 'users', 'integrations', 'directory', 'recruiting', 'scripts', 'google-workspace',
                    'channels', 'people', 'time-off', 'documents', 'workflows', 'perform', 'pulse', 'knowledge', 'desk',
                    'safe-speak', 'assets', 'hiring-requests', 'time', 'reports', 'overview',
                ],
            ]);
    }

    public function test_blocked_user_with_live_session_gets_403(): void
    {
        $user = User::factory()->blocked()->create();

        $this->actingAs($user)->getJson('/api/auth/me')->assertForbidden()->assertJsonPath('message', 'blocked');
    }

    public function test_locale_is_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson('/api/auth/me/locale', ['locale' => 'ru'])
            ->assertOk()
            ->assertJsonPath('locale', 'ru');
        $this->assertSame('ru', $user->refresh()->locale);
    }

    public function test_unsupported_locale_is_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson('/api/auth/me/locale', ['locale' => 'de'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('locale');
    }

    public function test_logout_ends_the_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/auth/logout')->assertNoContent();
        $this->assertGuest('web');
    }

    public function test_logout_requires_authentication(): void
    {
        $this->postJson('/api/auth/logout')->assertUnauthorized();
    }
}
