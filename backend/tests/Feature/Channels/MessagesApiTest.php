<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Integrations\Contracts\HostResolver;
use App\Modules\Integrations\Enums\IntegrationStatus;
use App\Modules\Integrations\Models\IntegrationLog;
use App\Modules\Recruiting\Enums\Channel;
use App\Modules\Recruiting\Enums\Direction;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\Touchpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\ChannelFixtures;
use Tests\Support\FakeHostResolver;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

final class MessagesApiTest extends TestCase
{
    use ChannelFixtures, RecruitingFixtures, RefreshDatabase;

    private Branch $branch;

    private User $recruiter;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->app->instance(HostResolver::class, new FakeHostResolver);
        $this->branch = Branch::factory()->create();
        $this->recruiter = $this->userWith(UserRole::Recruiter, [$this->branch]);
        $this->application = $this->applied($this->vacancyIn($this->branch), [
            'phone' => '+380671234567', 'telegram_username' => 'cand_user',
        ]);
    }

    public function test_authorization(): void
    {
        $url = $this->url();
        $this->postJson($url, ['channel' => 'telegram', 'text' => 'x'])->assertUnauthorized();
        $this->actingAs($this->userWith(UserRole::Viewer, [$this->branch]))->postJson($url, ['channel' => 'telegram', 'text' => 'x'])->assertForbidden();
        $this->actingAs($this->userWith(UserRole::Recruiter, [Branch::factory()->create()]))
            ->postJson($url, ['channel' => 'telegram', 'text' => 'x'])->assertForbidden();
        $this->actingAs($this->recruiter)->postJson('/api/candidates/999999/messages', ['channel' => 'telegram', 'text' => 'x'])->assertNotFound();
    }

    public function test_validation(): void
    {
        $this->actingAs($this->recruiter)->postJson($this->url(), ['channel' => 'email', 'text' => 'x'])->assertUnprocessable();
        $this->actingAs($this->recruiter)->postJson($this->url(), ['channel' => 'telegram', 'text' => ''])->assertUnprocessable();
        $this->actingAs($this->recruiter)->postJson($this->url(), ['channel' => 'telegram', 'text' => str_repeat('a', 4097)])->assertUnprocessable();
    }

    public function test_channel_off_is_not_connected(): void
    {
        $this->actingAs($this->recruiter)->postJson($this->url(), ['channel' => 'viber', 'text' => 'Hello'])
            ->assertUnprocessable()->assertJsonPath('code', 'channel_not_connected');
        $this->assertSame(0, Touchpoint::query()->where('channel', 'viber')->count());
    }

    public function test_demo_mode_records_without_calling_the_provider(): void
    {
        $this->telegram(IntegrationStatus::Demo);
        $this->actingAs($this->recruiter)->postJson($this->url(), ['channel' => 'telegram', 'text' => 'Запрошуємо на співбесіду'])
            ->assertCreated()
            ->assertJsonPath('data.channel', 'telegram')
            ->assertJsonPath('data.direction', 'out')
            ->assertJsonPath('data.via_product', true)
            ->assertJsonPath('data.author.id', $this->recruiter->id)
            ->assertJsonPath('data.meta.demo', true);
        Http::assertNothingSent();
    }

    public function test_telegram_replies_into_the_known_business_chat(): void
    {
        $this->telegram();
        $this->telegramWebhook($this->tgMessage(777, 777, 10, 'hi'))->assertOk();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 11]])]);

        $this->actingAs($this->recruiter)->postJson($this->url(), ['channel' => 'telegram', 'text' => 'Добрий день!'])
            ->assertCreated()->assertJsonPath('data.candidate_id', $this->application->candidate_id);

        Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/sendMessage')
            && $r['business_connection_id'] === 'bc-1' && $r['chat_id'] === '777' && $r['text'] === 'Добрий день!');
        $out = Touchpoint::query()->where('external_id', 'bc-1:11')->sole();
        $this->assertTrue($out->via_product);
        $this->assertSame($this->application->id, $out->application_id);
        // The same message echoed back by a webhook is not stored twice.
        $this->telegramWebhook($this->tgMessage(999, 777, 11, 'Добрий день!', null))->assertOk();
        $this->assertSame(1, Touchpoint::query()->where('external_id', 'bc-1:11')->count());
    }

    public function test_telegram_without_conversation(): void
    {
        $this->telegram();
        $this->actingAs($this->recruiter)->postJson($this->url(), ['channel' => 'telegram', 'text' => 'hi'])
            ->assertUnprocessable()->assertJsonPath('code', 'no_conversation');
        $this->assertSame(1, IntegrationLog::query()->where('message', 'send_failed')->count());
        Http::assertNothingSent();
    }

    public function test_whatsapp_24h_window(): void
    {
        $this->whatsapp();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);

        // Never wrote to us → template required, no provider call.
        $this->actingAs($this->recruiter)->postJson($this->url(), ['channel' => 'whatsapp', 'text' => 'hi'])
            ->assertUnprocessable()->assertJsonPath('code', 'template_required');
        Http::assertNothingSent();

        $this->ingest(Channel::Whatsapp, '+380671234567', ['direction' => Direction::In, 'at' => now()->subHours(25), 'external_id' => 'old']);
        $this->actingAs($this->recruiter)->postJson($this->url(), ['channel' => 'whatsapp', 'text' => 'hi'])
            ->assertUnprocessable()->assertJsonPath('code', 'template_required');

        $this->whatsappWebhook($this->waMessage('380671234567', 'wamid.IN1', 'Hello'))->assertOk();
        $this->actingAs($this->recruiter)->postJson($this->url(), ['channel' => 'whatsapp', 'text' => 'Thanks!'])->assertCreated();
        Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/1098765432/messages')
            && $r['to'] === '380671234567' && $r['text']['body'] === 'Thanks!' && $r->hasHeader('Authorization', 'Bearer '.self::WA_TOKEN));
        $this->assertSame(1, Touchpoint::query()->where('external_id', 'wamid.OUT1')->count());
    }

    public function test_whatsapp_provider_says_template_required(): void
    {
        $this->whatsapp();
        $this->whatsappWebhook($this->waMessage('380671234567', 'wamid.IN1', 'Hello'))->assertOk();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 131047, 'message' => 'Re-engagement']], 400)]);

        $this->actingAs($this->recruiter)->postJson($this->url(), ['channel' => 'whatsapp', 'text' => 'hi'])
            ->assertUnprocessable()->assertJsonPath('code', 'template_required');
    }

    public function test_viber_send_and_provider_failure(): void
    {
        $this->viber();
        $this->viberWebhook(['event' => 'message', 'message_token' => 1, 'sender' => ['id' => 'vUser=='], 'message' => ['type' => 'text', 'text' => 'hi']])->assertOk();
        $this->actingAs($this->userWith(UserRole::Admin))->postJson('/api/inbox/'.Touchpoint::query()->where('channel', 'viber')->value('id').'/link', [
            'candidate_id' => $this->application->candidate_id,
        ])->assertOk();

        Http::fake(['chatapi.viber.com/*' => Http::sequence()
            ->push(['status' => 0, 'message_token' => 5])
            ->push('oops', 500)]);
        $this->actingAs($this->recruiter)->postJson($this->url(), ['channel' => 'viber', 'text' => 'Hello'])->assertCreated();
        Http::assertSent(fn (Request $r): bool => $r['receiver'] === 'vUser==' && $r->hasHeader('X-Viber-Auth-Token', self::VIBER_TOKEN));

        $this->actingAs($this->recruiter)->postJson($this->url(), ['channel' => 'viber', 'text' => 'Again'])
            ->assertStatus(502)->assertJsonPath('code', 'send_failed');
        $this->assertStringNotContainsString(self::VIBER_TOKEN, (string) json_encode(IntegrationLog::query()->get()->toArray()));
    }

    public function test_application_must_belong_to_candidate(): void
    {
        $this->telegram(IntegrationStatus::Demo);
        $other = $this->applied($this->vacancyIn($this->branch));
        $this->actingAs($this->recruiter)->postJson($this->url(), ['channel' => 'telegram', 'text' => 'x', 'application_id' => $other->id])
            ->assertUnprocessable()->assertJsonPath('code', 'application_mismatch');
    }

    public function test_channel_availability_for_the_card(): void
    {
        $this->telegram(IntegrationStatus::Demo);
        $this->whatsapp();
        $data = $this->actingAs($this->recruiter)->getJson('/api/channels')->assertOk()->collect('data')->keyBy('key');

        $this->assertSame('demo', $data['telegram_business']['mode']);
        $this->assertSame('live', $data['whatsapp_cloud']['mode']);
        $this->assertSame('off', $data['viber']['mode']);
        $this->assertSame('call', $data['phonet']['channel']);
        $this->getJson('/api/channels')->assertOk();
        auth()->forgetGuards();
        $this->getJson('/api/channels')->assertUnauthorized();
    }

    public function test_click_to_call(): void
    {
        $url = '/api/candidates/'.$this->application->candidate_id.'/call';
        $this->actingAs($this->userWith(UserRole::Viewer, [$this->branch]))->postJson($url)->assertForbidden();
        $this->actingAs($this->recruiter)->postJson($url)->assertUnprocessable()->assertJsonPath('code', 'telephony_not_connected');

        $this->channel('phonet', IntegrationStatus::Connected, ['api_key' => 'fake-phonet-key'], ['domain' => 'x']);
        $this->actingAs($this->recruiter)->postJson($url)->assertUnprocessable()->assertJsonPath('code', 'click_to_call_unsupported');

        $this->channel('phonet', IntegrationStatus::Off);
        $this->channel('ringostat', IntegrationStatus::Connected, ['api_key' => 'fake-ringostat-key'], ['project_id' => '1', 'callback_extension' => '101']);
        Http::fake(['api.ringostat.net/*' => Http::response(['status' => 'ok'])]);
        $this->actingAs($this->recruiter)->postJson($url)->assertStatus(202)->assertJsonPath('data.integration', 'ringostat');
        Http::assertSent(fn (Request $r): bool => $r->url() === 'https://api.ringostat.net/callback/outward_call'
            && $r['extension'] === '101' && $r['destination'] === '380671234567' && $r->hasHeader('Auth-key', 'fake-ringostat-key'));
        // The call itself arrives later through the call-end webhook; nothing is recorded now.
        $this->assertSame(0, Touchpoint::query()->where('channel', 'call')->count());
    }

    private function url(): string
    {
        return '/api/candidates/'.$this->application->candidate_id.'/messages';
    }
}
