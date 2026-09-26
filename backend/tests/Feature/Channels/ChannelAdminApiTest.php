<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Integrations\Contracts\HostResolver;
use App\Modules\Integrations\Contracts\SecretVault;
use App\Modules\Integrations\Enums\IntegrationStatus;
use App\Modules\Integrations\Models\IntegrationLog;
use App\Modules\Recruiting\Models\Touchpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\ChannelFixtures;
use Tests\Support\FakeHostResolver;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

final class ChannelAdminApiTest extends TestCase
{
    use ChannelFixtures, RecruitingFixtures, RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->app->instance(HostResolver::class, new FakeHostResolver);
        $this->superadmin = User::factory()->withRole(UserRole::Superadmin)->create();
    }

    public function test_only_superadmin(): void
    {
        $this->getJson('/api/channels/admin')->assertUnauthorized();
        foreach ([UserRole::Admin, UserRole::Recruiter] as $role) {
            $user = User::factory()->withRole($role)->create();
            $this->actingAs($user)->getJson('/api/channels/admin')->assertForbidden();
            $this->actingAs($user)->postJson('/api/channels/telegram_business/simulate')->assertForbidden();
            $this->actingAs($user)->postJson('/api/channels/telegram_business/register-webhook')->assertForbidden();
            $this->actingAs($user)->postJson('/api/channels/viber/test', ['to' => 'x'])->assertForbidden();
        }
        $this->actingAs($this->superadmin)->postJson('/api/channels/unknown/simulate')->assertNotFound();
    }

    public function test_overview_lists_webhook_urls_and_capabilities(): void
    {
        $this->telegram(IntegrationStatus::Demo);
        $data = $this->actingAs($this->superadmin)->getJson('/api/channels/admin')->assertOk()->collect('data')->keyBy('key');

        $this->assertSame(['telegram_business', 'whatsapp_cloud', 'viber', 'phonet', 'ringostat', 'binotel'], $data->keys()->all());
        $this->assertStringEndsWith('/api/webhooks/telegram_business', $data['telegram_business']['webhook_url']);
        $this->assertSame('demo', $data['telegram_business']['mode']);
        $this->assertTrue($data['telegram_business']['can_register']);
        $this->assertTrue($data['whatsapp_cloud']['handshake']);
        $this->assertSame('query_token', $data['binotel']['auth']);
        $this->assertTrue($data['ringostat']['can_call']);
        $this->assertFalse($data['phonet']['can_send']);
        $this->assertStringNotContainsString(self::TG_SECRET, (string) json_encode($data));
    }

    public function test_register_telegram_webhook_stores_a_new_secret_that_webhooks_then_use(): void
    {
        $this->channel('telegram_business', IntegrationStatus::Demo, ['bot_token' => self::TG_BOT_TOKEN]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);

        $this->actingAs($this->superadmin)->postJson('/api/channels/telegram_business/register-webhook')
            ->assertOk()->assertJsonPath('data.registered', true);

        $secret = $this->app->make(SecretVault::class)->get('telegram_business', 'webhook_secret');
        $this->assertIsString($secret);
        $this->assertSame(48, strlen($secret));
        Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/setWebhook')
            && str_ends_with((string) $r['url'], '/api/webhooks/telegram_business')
            && $r['secret_token'] === $secret
            && $r['allowed_updates'] === ['business_message', 'edited_business_message', 'business_connection']);
        auth()->forgetGuards();
        $this->telegramWebhook($this->tgMessage(5, 5, 1, 'hi'), $secret)->assertOk();
        $this->assertSame(1, IntegrationLog::query()->where('message', 'webhook_registered')->count());
    }

    public function test_failed_registration_keeps_the_old_secret(): void
    {
        $this->telegram(IntegrationStatus::Demo);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'bad'], 400)]);

        $this->actingAs($this->superadmin)->postJson('/api/channels/telegram_business/register-webhook')
            ->assertStatus(502)->assertJsonPath('code', 'send_failed');
        $this->assertSame(self::TG_SECRET, $this->app->make(SecretVault::class)->get('telegram_business', 'webhook_secret'));
        $this->assertSame(1, IntegrationLog::query()->where('message', 'webhook_register_failed')->count());
    }

    public function test_register_needs_integration_on_and_support(): void
    {
        $this->actingAs($this->superadmin)->postJson('/api/channels/telegram_business/register-webhook')
            ->assertUnprocessable()->assertJsonPath('code', 'channel_off');
        $this->channel('phonet', IntegrationStatus::Demo);
        $this->actingAs($this->superadmin)->postJson('/api/channels/phonet/register-webhook')
            ->assertUnprocessable()->assertJsonPath('code', 'unsupported');
        $this->channel('telegram_business', IntegrationStatus::Demo, ['bot_token' => 'broken token']);
        $this->actingAs($this->superadmin)->postJson('/api/channels/telegram_business/register-webhook')
            ->assertUnprocessable()->assertJsonPath('code', 'channel_not_connected');
        Http::assertNothingSent();
    }

    public function test_viber_registration(): void
    {
        $this->viber(IntegrationStatus::Demo);
        Http::fake(['chatapi.viber.com/*' => Http::response(['status' => 0])]);
        $this->actingAs($this->superadmin)->postJson('/api/channels/viber/register-webhook')->assertOk();
        Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/pa/set_webhook') && $r->hasHeader('X-Viber-Auth-Token', self::VIBER_TOKEN));
    }

    public function test_send_test_message(): void
    {
        $this->telegram();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 3]])]);

        $this->actingAs($this->superadmin)->postJson('/api/channels/telegram_business/test', ['to' => '12345', 'text' => 'ping'])
            ->assertOk()->assertJsonPath('data.sent', true);
        Http::assertSent(fn (Request $r): bool => $r['chat_id'] === '12345' && $r['text'] === 'ping' && ! isset($r['business_connection_id']));
        $this->actingAs($this->superadmin)->postJson('/api/channels/telegram_business/test', ['to' => '@name'])
            ->assertUnprocessable()->assertJsonPath('code', 'invalid_recipient');
        $this->actingAs($this->superadmin)->postJson('/api/channels/telegram_business/test', [])->assertUnprocessable();
        $this->actingAs($this->superadmin)->postJson('/api/channels/phonet/test', ['to' => '1'])->assertUnprocessable()->assertJsonPath('code', 'unsupported');
        $this->assertSame(0, Touchpoint::query()->count());
        $this->assertSame(1, IntegrationLog::query()->where('message', 'test_sent')->count());
    }

    public function test_simulate_only_in_demo_and_through_the_ingest_path(): void
    {
        $this->actingAs($this->superadmin)->postJson('/api/channels/viber/simulate')
            ->assertUnprocessable()->assertJsonPath('code', 'not_demo');
        $this->telegram(IntegrationStatus::Connected);
        $this->actingAs($this->superadmin)->postJson('/api/channels/telegram_business/simulate')
            ->assertUnprocessable()->assertJsonPath('code', 'not_demo');

        foreach (['telegram_business', 'whatsapp_cloud', 'viber', 'phonet', 'ringostat', 'binotel'] as $key) {
            $this->channel($key, IntegrationStatus::Demo);
            $this->actingAs($this->superadmin)->postJson("/api/channels/$key/simulate")
                ->assertCreated()->assertJsonPath('data.created', 1)->assertJsonPath('data.touchpoints.0.meta.demo', true);
        }
        $this->assertSame(6, Touchpoint::query()->whereNull('candidate_id')->count());
        Http::assertNothingSent();
    }

    public function test_simulate_from_an_existing_candidate_matches_them(): void
    {
        $application = $this->applied($this->vacancyIn(Branch::factory()->create()), ['phone' => '+380501234567', 'telegram_username' => 'demo_person']);
        foreach (['whatsapp_cloud', 'telegram_business', 'binotel'] as $key) {
            $this->channel($key, IntegrationStatus::Demo);
            $this->actingAs($this->superadmin)->postJson("/api/channels/$key/simulate", ['candidate_id' => $application->candidate_id, 'text' => 'Привіт'])
                ->assertCreated()->assertJsonPath('data.touchpoints.0.candidate_id', $application->candidate_id);
        }
        $this->actingAs($this->superadmin)->postJson('/api/channels/binotel/simulate', ['candidate_id' => 999999])->assertUnprocessable();
    }
}
