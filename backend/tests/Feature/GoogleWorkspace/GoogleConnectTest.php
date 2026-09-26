<?php

declare(strict_types=1);

namespace Tests\Feature\GoogleWorkspace;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\GoogleWorkspace\Http\Controllers\GoogleConnectController;
use App\Modules\Integrations\Contracts\SecretVault;
use App\Modules\Integrations\Models\Integration;
use App\Modules\Integrations\Models\IntegrationLog;
use App\Modules\Integrations\Models\IntegrationSecret;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\GoogleFixtures;
use Tests\TestCase;

/** OAuth consent for Gmail / Calendar / Sheets. Google is faked; every value is synthetic. */
final class GoogleConnectTest extends TestCase
{
    use GoogleFixtures;
    use RefreshDatabase;

    private const string CALLBACK = '/api/google/connect/callback';

    private const string STATE = 'fake-state-0123456789';

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->configureGoogleClient();
        $this->captureLogs();
        $this->superadmin = User::factory()->withRole(UserRole::Superadmin)->create();
    }

    public function test_guest_is_401_and_other_roles_403(): void
    {
        $this->get('/api/google/connect')->assertUnauthorized();
        $admin = User::factory()->withRole(UserRole::Admin)->create();
        $this->actingAs($admin)->get('/api/google/connect')->assertForbidden();
        $this->actingAs($admin)->getJson('/api/google/status')->assertForbidden();
    }

    public function test_redirect_asks_for_offline_consent_with_the_service_scopes_and_keeps_state_in_session(): void
    {
        $response = $this->actingAs($this->superadmin)->get('/api/google/connect?services=gmail,calendar');

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame(self::CLIENT_ID, $query['client_id']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertSame('https://sinhrm.example.test/api/google/connect/callback', $query['redirect_uri']);
        $scopes = explode(' ', (string) $query['scope']);
        $this->assertContains('https://www.googleapis.com/auth/gmail.readonly', $scopes);
        $this->assertContains('https://www.googleapis.com/auth/gmail.send', $scopes);
        $this->assertContains('https://www.googleapis.com/auth/calendar.events', $scopes);
        $this->assertContains('openid', $scopes);
        $this->assertContains('email', $scopes);
        $this->assertNotContains('https://www.googleapis.com/auth/spreadsheets.readonly', $scopes);
        $saved = session(GoogleConnectController::SESSION_KEY);
        $this->assertSame($query['state'], $saved['state']);
        $this->assertSame(['gmail', 'calendar'], $saved['services']);
        $this->assertStringNotContainsString(self::CLIENT_SECRET, $location);
    }

    public function test_redirect_without_oauth_client_goes_back_with_an_error_code(): void
    {
        config(['services.google.client_secret' => null]);

        $this->actingAs($this->superadmin)->get('/api/google/connect')
            ->assertRedirect('/admin/integrations?google_error=google_oauth_not_configured');
    }

    public function test_callback_with_wrong_state_is_refused_without_any_request(): void
    {
        Http::fake();

        $this->actingAs($this->superadmin)
            ->withSession([GoogleConnectController::SESSION_KEY => ['state' => self::STATE, 'services' => ['gmail']]])
            ->get(self::CALLBACK.'?code=fake-code&state=other-state')
            ->assertRedirect('/admin/integrations?google_error=invalid_state');
        Http::assertNothingSent();

        // No session state at all.
        $this->actingAs($this->superadmin)->get(self::CALLBACK.'?code=fake-code&state='.self::STATE)
            ->assertRedirect('/admin/integrations?google_error=invalid_state');
    }

    public function test_denied_consent_redirects_back(): void
    {
        Http::fake();

        $this->actingAs($this->superadmin)
            ->withSession([GoogleConnectController::SESSION_KEY => ['state' => self::STATE, 'services' => ['gmail']]])
            ->get(self::CALLBACK.'?error=access_denied&state='.self::STATE)
            ->assertRedirect('/admin/integrations?google_error=consent_denied');
        Http::assertNothingSent();
    }

    public function test_callback_exchanges_the_code_and_stores_tokens_only_in_the_vault(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response($this->tokenAnswer([
            'https://www.googleapis.com/auth/gmail.readonly',
            'https://www.googleapis.com/auth/gmail.send',
            'https://www.googleapis.com/auth/calendar.events',
            'https://www.googleapis.com/auth/spreadsheets.readonly',
        ]))]);

        $this->actingAs($this->superadmin)
            ->withSession([GoogleConnectController::SESSION_KEY => ['state' => self::STATE, 'services' => ['gmail', 'calendar', 'sheets']]])
            ->get(self::CALLBACK.'?code=fake-auth-code&state='.self::STATE)
            ->assertRedirect('/admin/integrations?connected=google');

        Http::assertSent(fn (Request $r): bool => $r->url() === 'https://oauth2.googleapis.com/token'
            && $r['grant_type'] === 'authorization_code'
            && $r['code'] === 'fake-auth-code'
            && $r['client_id'] === self::CLIENT_ID
            && $r['redirect_uri'] === 'https://sinhrm.example.test/api/google/connect/callback');

        $vault = $this->app->make(SecretVault::class);
        foreach (['google_gmail', 'google_calendar', 'google_sheets'] as $key) {
            $this->assertSame(self::REFRESH_TOKEN, $vault->get($key, 'refresh_token'));
            $integration = Integration::query()->where('key', $key)->firstOrFail();
            $this->assertSame('connected', $integration->status->value);
            $this->assertSame('recruiting-box@example.test', $integration->settings['account_email']);
            $this->assertSame($this->superadmin->id, $integration->settings['connected_by']);
        }
        // Encrypted at rest.
        $this->assertStringNotContainsString(self::REFRESH_TOKEN, (string) json_encode(IntegrationSecret::query()->getQuery()->get()));

        $status = $this->actingAs($this->superadmin)->getJson('/api/google/status')->assertOk();
        $status->assertJsonPath('data.0.service', 'gmail')->assertJsonPath('data.0.connected', true)
            ->assertJsonPath('data.0.account_email', 'recruiting-box@example.test')
            ->assertJsonPath('data.0.scopes.0', 'https://www.googleapis.com/auth/gmail.readonly')
            ->assertJsonPath('data.0.can_send', true);
        $integrations = $this->actingAs($this->superadmin)->getJson('/api/integrations')->assertOk();
        foreach ([$status->getContent(), $integrations->getContent(), json_encode(IntegrationLog::query()->get()->toArray())] as $dump) {
            $this->assertStringNotContainsString(self::REFRESH_TOKEN, (string) $dump);
            $this->assertStringNotContainsString(self::ACCESS_TOKEN, (string) $dump);
            $this->assertStringNotContainsString('fake-auth-code', (string) $dump);
        }
        $this->assertLogsDoNotContain(self::REFRESH_TOKEN, self::ACCESS_TOKEN, 'fake-auth-code', self::CLIENT_SECRET);
    }

    public function test_services_whose_scopes_were_not_granted_stay_disconnected(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response($this->tokenAnswer([
            'https://www.googleapis.com/auth/gmail.readonly',
        ]))]);

        $this->actingAs($this->superadmin)
            ->withSession([GoogleConnectController::SESSION_KEY => ['state' => self::STATE, 'services' => ['gmail', 'calendar']]])
            ->get(self::CALLBACK.'?code=fake-auth-code&state='.self::STATE)
            ->assertRedirect('/admin/integrations?connected=google&missing=calendar');

        $this->actingAs($this->superadmin)->getJson('/api/google/status')
            ->assertJsonPath('data.0.connected', true)
            ->assertJsonPath('data.0.can_send', false)
            ->assertJsonPath('data.0.scopes', ['https://www.googleapis.com/auth/gmail.readonly'])
            ->assertJsonPath('data.1.service', 'calendar')
            ->assertJsonPath('data.1.connected', false);
        $this->actingAs($this->superadmin)->getJson('/api/google/calendar')->assertJsonPath('data.connected', false);
    }

    public function test_state_is_single_use(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response($this->tokenAnswer([
            'https://www.googleapis.com/auth/spreadsheets.readonly',
        ]))]);
        $session = [GoogleConnectController::SESSION_KEY => ['state' => self::STATE, 'services' => ['sheets']]];

        $this->actingAs($this->superadmin)->withSession($session)
            ->get(self::CALLBACK.'?code=fake-auth-code&state='.self::STATE)
            ->assertRedirect('/admin/integrations?connected=google');
        $this->actingAs($this->superadmin)->get(self::CALLBACK.'?code=fake-auth-code&state='.self::STATE)
            ->assertRedirect('/admin/integrations?google_error=invalid_state');
        Http::assertSentCount(1);
    }

    public function test_failed_code_exchange_is_reported_as_a_code(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Bad code fake-auth-code'], 400)]);

        $this->actingAs($this->superadmin)
            ->withSession([GoogleConnectController::SESSION_KEY => ['state' => self::STATE, 'services' => ['gmail']]])
            ->get(self::CALLBACK.'?code=fake-auth-code&state='.self::STATE)
            ->assertRedirect('/admin/integrations?google_error=reconnect_required');
        $this->assertNull(Integration::query()->where('key', 'google_gmail')->first());
        $this->assertLogsDoNotContain('fake-auth-code', self::CLIENT_SECRET);
    }

    /**
     * @param  list<string>  $scopes
     * @return array<string, mixed>
     */
    private function tokenAnswer(array $scopes): array
    {
        return [
            'access_token' => self::ACCESS_TOKEN,
            'refresh_token' => self::REFRESH_TOKEN,
            'expires_in' => 3599,
            'token_type' => 'Bearer',
            'scope' => implode(' ', ['openid', 'https://www.googleapis.com/auth/userinfo.email', ...$scopes]),
            'id_token' => $this->idToken('Recruiting-Box@example.test'),
        ];
    }
}
