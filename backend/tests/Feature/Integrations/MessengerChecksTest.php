<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Integrations\Contracts\HostResolver;
use App\Modules\Integrations\Enums\IntegrationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\ChannelFixtures;
use Tests\Support\FakeHostResolver;
use Tests\TestCase;

/** Read-only connection checks of WhatsApp Cloud and Viber (fake tokens, Http::fake). */
final class MessengerChecksTest extends TestCase
{
    use ChannelFixtures, RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->app->instance(HostResolver::class, new FakeHostResolver);
        $this->superadmin = User::factory()->withRole(UserRole::Superadmin)->create();
    }

    public function test_whatsapp_check(): void
    {
        $this->whatsapp(IntegrationStatus::Demo);
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['id' => '1098765432'])
            ->push(['error' => ['code' => 190, 'message' => 'expired']], 400)]);

        $this->actingAs($this->superadmin)->postJson('/api/integrations/whatsapp_cloud/check')->assertOk()->assertJsonPath('data.status', 'connected');
        Http::assertSent(fn (Request $r): bool => $r->method() === 'GET' && str_contains($r->url(), '/1098765432?fields=id')
            && $r->hasHeader('Authorization', 'Bearer '.self::WA_TOKEN));
        $this->actingAs($this->superadmin)->postJson('/api/integrations/whatsapp_cloud/check')
            ->assertOk()->assertJsonPath('data.status', 'error')->assertJsonPath('data.last_error', 'unauthorized');
    }

    public function test_whatsapp_check_rejects_bad_phone_number_id_without_a_request(): void
    {
        $this->channel('whatsapp_cloud', IntegrationStatus::Demo, ['access_token' => self::WA_TOKEN], ['phone_number_id' => '../me', 'waba_id' => '1']);
        $this->actingAs($this->superadmin)->postJson('/api/integrations/whatsapp_cloud/check')->assertOk()->assertJsonPath('data.last_error', 'invalid_url');
        Http::assertNothingSent();
    }

    public function test_viber_check(): void
    {
        $this->viber(IntegrationStatus::Demo);
        Http::fake(['chatapi.viber.com/*' => Http::sequence()->push(['status' => 0, 'name' => 'Bot'])->push(['status' => 2])]);

        $this->actingAs($this->superadmin)->postJson('/api/integrations/viber/check')->assertOk()->assertJsonPath('data.status', 'connected');
        Http::assertSent(fn (Request $r): bool => $r->hasHeader('X-Viber-Auth-Token', self::VIBER_TOKEN));
        $this->actingAs($this->superadmin)->postJson('/api/integrations/viber/check')->assertOk()->assertJsonPath('data.last_error', 'unauthorized');
    }
}
