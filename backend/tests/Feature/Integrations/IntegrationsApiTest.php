<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Integrations\Models\IntegrationLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class IntegrationsApiTest extends TestCase
{
    use RefreshDatabase;

    /** Fake values only: the repository is public. */
    private const string BOT_TOKEN = '123456:FAKE-bot-token-for-tests-9876';

    private const string PROJECT_KEY = 'fake-project-key-abcd';

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->superadmin = User::factory()->withRole(UserRole::Superadmin)->create();
    }

    public function test_guest_gets_401_everywhere(): void
    {
        $this->getJson('/api/integrations')->assertUnauthorized();
        $this->putJson('/api/integrations/telegram_business', [])->assertUnauthorized();
        $this->postJson('/api/integrations/telegram_business/check')->assertUnauthorized();
        $this->putJson('/api/integrations/ai-policy', ['enabled' => true])->assertUnauthorized();
    }

    public function test_non_superadmin_and_blocked_superadmin_get_403(): void
    {
        foreach ([UserRole::Admin, UserRole::Recruiter, UserRole::Viewer] as $role) {
            $user = User::factory()->withRole($role)->create();
            $this->actingAs($user)->getJson('/api/integrations')->assertForbidden();
            $this->actingAs($user)->putJson('/api/integrations/ai-policy', ['enabled' => true])->assertForbidden();
        }
        $blocked = User::factory()->withRole(UserRole::Superadmin)->blocked()->create();
        $this->actingAs($blocked)->getJson('/api/integrations/telegram_business/logs')->assertForbidden();
    }

    public function test_unknown_key_is_404(): void
    {
        $this->actingAs($this->superadmin)->putJson('/api/integrations/nope', [])->assertNotFound();
        $this->actingAs($this->superadmin)->postJson('/api/integrations/nope/check')->assertNotFound();
        $this->actingAs($this->superadmin)->postJson('/api/integrations/nope/status', ['status' => 'off'])->assertNotFound();
        $this->actingAs($this->superadmin)->getJson('/api/integrations/nope/logs')->assertNotFound();
    }

    public function test_list_returns_all_definitions_with_defaults_and_ai_policy_off(): void
    {
        $response = $this->actingAs($this->superadmin)->getJson('/api/integrations')->assertOk()
            ->assertJsonPath('ai_policy.enabled', false);

        $byKey = $this->byKey($response);
        $this->assertCount(18, $byKey);
        $this->assertSame('ai', $byKey['ai_broker']['group']);
        $this->assertSame('off', $byKey['ai_broker']['status']);
        $this->assertTrue($byKey['ai_broker']['supports_check']);
        $this->assertFalse($byKey['openrouter']['supports_check']);
        $this->assertSame('https://aib.zapleo.com', $byKey['ai_broker']['fields'][0]['default']);
        $this->assertSame(['is_set' => false, 'updated_at' => null, 'masked' => null], $byKey['ai_broker']['fields'][1]['secret']);
    }

    public function test_list_masks_secrets_and_never_leaks_the_value(): void
    {
        $this->putTelegramToken();

        $response = $this->actingAs($this->superadmin)->getJson('/api/integrations')->assertOk();

        $field = $this->byKey($response)['telegram_business']['fields'][0];
        $this->assertTrue($field['secret']['is_set']);
        $this->assertSame('••••9876', $field['secret']['masked']);
        $this->assertNotNull($field['secret']['updated_at']);
        $this->assertArrayNotHasKey('value', $field);
        $this->assertSecretAbsent($response);
    }

    public function test_secret_is_encrypted_at_rest(): void
    {
        $this->putTelegramToken();

        $raw = (string) $this->app['db']->table('integration_secrets')->value('value');
        $this->assertNotSame(self::BOT_TOKEN, $raw);
        $this->assertStringNotContainsString('FAKE-bot-token', $raw);
    }

    public function test_secret_set_unchanged_and_delete_semantics(): void
    {
        $this->putTelegramToken();

        // "" = unchanged
        $this->actingAs($this->superadmin)
            ->putJson('/api/integrations/telegram_business', ['secrets' => ['bot_token' => '']])
            ->assertOk()->assertJsonPath('data.fields.0.secret.is_set', true);
        // absent = unchanged
        $this->actingAs($this->superadmin)
            ->putJson('/api/integrations/telegram_business', [])
            ->assertOk()->assertJsonPath('data.fields.0.secret.is_set', true);
        // null = delete
        $this->actingAs($this->superadmin)
            ->putJson('/api/integrations/telegram_business', ['secrets' => ['bot_token' => null]])
            ->assertOk()->assertJsonPath('data.fields.0.secret.is_set', false);
        $this->assertDatabaseCount('integration_secrets', 0);
    }

    public function test_update_saves_settings_and_validates_against_field_spec(): void
    {
        $this->actingAs($this->superadmin)
            ->putJson('/api/integrations/ai_broker', ['settings' => ['base_url' => 'https://broker.example.test', 'daily_cap_usd' => '5']])
            ->assertOk()
            ->assertJsonPath('data.fields.0.value', 'https://broker.example.test')
            ->assertJsonPath('data.fields.2.value', '5');

        // bad URL, required field emptied, unknown settings key, unknown secret, non-string secret
        $this->actingAs($this->superadmin)
            ->putJson('/api/integrations/ai_broker', ['settings' => ['base_url' => 'not a url']])
            ->assertUnprocessable()->assertJsonValidationErrors('settings.base_url');
        $this->actingAs($this->superadmin)
            ->putJson('/api/integrations/ai_broker', ['settings' => ['base_url' => '']])
            ->assertUnprocessable()->assertJsonValidationErrors('settings.base_url');
        $this->actingAs($this->superadmin)
            ->putJson('/api/integrations/ai_broker', ['settings' => ['evil' => 'x']])
            ->assertUnprocessable()->assertJsonValidationErrors('settings');
        $this->actingAs($this->superadmin)
            ->putJson('/api/integrations/ai_broker', ['secrets' => ['other' => 'x']])
            ->assertUnprocessable()->assertJsonValidationErrors('secrets');
        $this->actingAs($this->superadmin)
            ->putJson('/api/integrations/ai_broker', ['secrets' => ['project_key' => ['x']]])
            ->assertUnprocessable()->assertJsonValidationErrors('secrets.project_key');
        $this->actingAs($this->superadmin)
            ->putJson('/api/integrations/whatsapp_cloud', ['settings' => ['phone_number_id' => '1']])
            ->assertUnprocessable()->assertJsonValidationErrors('settings.waba_id');
    }

    public function test_check_telegram_ok(): void
    {
        $this->putTelegramToken();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['id' => 1]])]);

        $response = $this->actingAs($this->superadmin)->postJson('/api/integrations/telegram_business/check')
            ->assertOk()
            ->assertJsonPath('data.status', 'connected')
            ->assertJsonPath('data.last_error', null);

        $this->assertNotNull($response->json('data.last_checked_at'));
        Http::assertSent(fn (Request $r): bool => $r->method() === 'GET'
            && $r->url() === 'https://api.telegram.org/bot'.self::BOT_TOKEN.'/getMe');
        $this->assertSecretAbsent($response);
    }

    public function test_check_telegram_401_sets_error_without_leaking_token(): void
    {
        $this->putTelegramToken();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401)]);

        $response = $this->actingAs($this->superadmin)->postJson('/api/integrations/telegram_business/check')
            ->assertOk()
            ->assertJsonPath('data.status', 'error')
            ->assertJsonPath('data.last_error', 'unauthorized');

        $this->assertSecretAbsent($response);
        $logs = $this->actingAs($this->superadmin)->getJson('/api/integrations/telegram_business/logs')->assertOk();
        $this->assertSame('check_error', $logs->json('data.0.message'));
        $this->assertSecretAbsent($logs);
        $this->assertStringNotContainsString('FAKE-bot-token', (string) json_encode(IntegrationLog::query()->get()->toArray()));
    }

    public function test_check_telegram_connection_failure_does_not_expose_url(): void
    {
        $this->putTelegramToken();
        Http::fake(fn () => throw new ConnectionException('cURL error for https://api.telegram.org/bot'.self::BOT_TOKEN.'/getMe'));

        $response = $this->actingAs($this->superadmin)->postJson('/api/integrations/telegram_business/check')
            ->assertOk()->assertJsonPath('data.last_error', 'connection_failed');
        $this->assertSecretAbsent($response);
    }

    public function test_check_without_required_secret_is_error_and_makes_no_request(): void
    {
        Http::fake();

        $this->actingAs($this->superadmin)->postJson('/api/integrations/telegram_business/check')
            ->assertOk()
            ->assertJsonPath('data.status', 'error')
            ->assertJsonPath('data.last_error', 'missing_secret:bot_token');
        Http::assertNothingSent();
    }

    public function test_check_ai_broker_calls_only_public_health_without_key(): void
    {
        $this->actingAs($this->superadmin)
            ->putJson('/api/integrations/ai_broker', ['secrets' => ['project_key' => self::PROJECT_KEY]])->assertOk();
        Http::fake(['aib.zapleo.com/v1/health' => Http::response(['status' => 'ok'])]);

        $this->actingAs($this->superadmin)->postJson('/api/integrations/ai_broker/check')
            ->assertOk()->assertJsonPath('data.status', 'connected');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r): bool => $r->url() === 'https://aib.zapleo.com/v1/health'
            && ! str_contains(json_encode($r->headers()) ?: '', self::PROJECT_KEY));
    }

    public function test_check_ai_broker_health_failure(): void
    {
        $this->actingAs($this->superadmin)
            ->putJson('/api/integrations/ai_broker', ['secrets' => ['project_key' => self::PROJECT_KEY]])->assertOk();
        Http::fake(['aib.zapleo.com/*' => Http::response('down', 503)]);

        $this->actingAs($this->superadmin)->postJson('/api/integrations/ai_broker/check')
            ->assertOk()->assertJsonPath('data.status', 'error')->assertJsonPath('data.last_error', 'http_503');
    }

    public function test_sintegrum_check_is_offline_and_reports_not_verified(): void
    {
        Http::fake();
        $this->actingAs($this->superadmin)
            ->putJson('/api/integrations/sintegrum_api', ['secrets' => ['token' => 'fake-sintegrum-token']])->assertOk();

        $this->actingAs($this->superadmin)->postJson('/api/integrations/sintegrum_api/check')
            ->assertOk()->assertJsonPath('data.status', 'demo')->assertJsonPath('data.last_error', 'not_verified');
        Http::assertNothingSent();
    }

    public function test_check_not_supported_is_422(): void
    {
        $this->actingAs($this->superadmin)->postJson('/api/integrations/openrouter/check')
            ->assertUnprocessable()->assertJsonPath('code', 'check_not_supported');
    }

    public function test_manual_status_switch_accepts_only_off_and_demo(): void
    {
        $this->actingAs($this->superadmin)->postJson('/api/integrations/viber/status', ['status' => 'demo'])
            ->assertOk()->assertJsonPath('data.status', 'demo');
        $this->actingAs($this->superadmin)->postJson('/api/integrations/viber/status', ['status' => 'connected'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->actingAs($this->superadmin)->getJson('/api/integrations/viber/logs')
            ->assertOk()->assertJsonPath('data.0.message', 'status_changed')
            ->assertJsonPath('data.0.context.to', 'demo');
    }

    public function test_logs_record_field_names_but_never_values(): void
    {
        $this->putTelegramToken();
        $this->actingAs($this->superadmin)
            ->putJson('/api/integrations/telegram_business', ['secrets' => ['bot_token' => null]])->assertOk();

        $logs = $this->actingAs($this->superadmin)->getJson('/api/integrations/telegram_business/logs')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.context.secrets_cleared', ['bot_token'])
            ->assertJsonPath('data.1.context.secrets_set', ['bot_token'])
            ->assertJsonPath('data.1.context.user_id', $this->superadmin->id);
        $this->assertSecretAbsent($logs);
    }

    public function test_logs_are_limited_to_50(): void
    {
        for ($i = 0; $i < 55; $i++) {
            $this->actingAs($this->superadmin)->postJson('/api/integrations/viber/status', ['status' => $i % 2 ? 'off' : 'demo']);
        }

        $this->actingAs($this->superadmin)->getJson('/api/integrations/viber/logs')->assertOk()->assertJsonCount(50, 'data');
    }

    public function test_ai_policy_toggle_is_logged(): void
    {
        $this->actingAs($this->superadmin)->putJson('/api/integrations/ai-policy', ['enabled' => 'yes'])
            ->assertUnprocessable();
        $this->actingAs($this->superadmin)->putJson('/api/integrations/ai-policy', ['enabled' => true])
            ->assertOk()->assertJsonPath('data.enabled', true);
        $this->actingAs($this->superadmin)->getJson('/api/integrations')->assertJsonPath('ai_policy.enabled', true);

        $log = IntegrationLog::query()->latest('id')->firstOrFail();
        $this->assertSame('ai_enabled', $log->message);
        $this->assertSame($this->superadmin->id, $log->context['user_id'] ?? null);
    }

    private function putTelegramToken(): void
    {
        $this->actingAs($this->superadmin)
            ->putJson('/api/integrations/telegram_business', ['secrets' => ['bot_token' => self::BOT_TOKEN]])
            ->assertOk()
            ->assertJsonPath('data.fields.0.secret.is_set', true);
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return array<string, array<string, mixed>>
     */
    private function byKey(TestResponse $response): array
    {
        /** @var list<array<string, mixed>> $items */
        $items = $response->json('data');

        return array_column($items, null, 'key');
    }

    /**
     * Scans the whole response body, not a single field.
     *
     * @param  TestResponse<Response>  $response
     */
    private function assertSecretAbsent(TestResponse $response): void
    {
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString(self::BOT_TOKEN, $body);
        $this->assertStringNotContainsString('FAKE-bot-token', $body);
        $this->assertStringNotContainsString(self::PROJECT_KEY, $body);
    }
}
