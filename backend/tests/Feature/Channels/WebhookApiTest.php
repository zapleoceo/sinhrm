<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Modules\Directory\Models\Branch;
use App\Modules\Integrations\Enums\IntegrationStatus;
use App\Modules\Integrations\Models\IntegrationLog;
use App\Modules\Recruiting\Models\Touchpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\ChannelFixtures;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

final class WebhookApiTest extends TestCase
{
    use ChannelFixtures, RecruitingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_unknown_key_and_switched_off_integration_are_404(): void
    {
        $this->postJson('/api/webhooks/nope', [])->assertNotFound();
        $this->postJson('/api/webhooks/Bad-Key', [])->assertNotFound();
        $this->telegram(IntegrationStatus::Off);
        $this->telegramWebhook($this->tgMessage(5, 5, 1, 'hi'))->assertNotFound();
        $this->postJson('/api/webhooks/phonet?token=x', [])->assertNotFound();
        $this->assertSame(0, Touchpoint::query()->count());
    }

    public function test_telegram_rejects_wrong_or_missing_secret(): void
    {
        $this->telegram();
        $this->telegramWebhook($this->tgMessage(5, 5, 1, 'hi'), 'wrong-secret')->assertForbidden();
        $this->postJson('/api/webhooks/telegram_business', $this->tgMessage(5, 5, 1, 'hi'))->assertForbidden();
        $this->assertSame(0, Touchpoint::query()->count());
        $this->assertSame(2, IntegrationLog::query()->where('message', 'webhook_rejected')->count());
    }

    public function test_secret_not_configured_rejects_everything(): void
    {
        $this->channel('telegram_business', IntegrationStatus::Demo, ['bot_token' => self::TG_BOT_TOKEN]);
        $this->telegramWebhook($this->tgMessage(5, 5, 1, 'hi'), '')->assertForbidden();
    }

    public function test_telegram_message_matches_candidate_by_username_and_is_idempotent(): void
    {
        $this->telegram();
        $application = $this->applied($this->vacancyIn(Branch::factory()->create()), ['telegram_username' => 'cand_user']);
        $update = $this->tgMessage(777, 777, 10, 'Добрий день, це Олена');

        $this->telegramWebhook($update)->assertOk()->assertExactJson(['ok' => true]);
        $this->telegramWebhook($update)->assertOk();

        $touch = Touchpoint::query()->where('channel', 'telegram')->sole();
        $this->assertSame($application->candidate_id, $touch->candidate_id);
        $this->assertSame($application->id, $touch->application_id);
        $this->assertSame('in', $touch->direction->value);
        $this->assertFalse($touch->via_product);
        $this->assertSame('bc-1:10', $touch->external_id);
        $this->assertSame('bc-1:777', $touch->meta['thread'] ?? null);
        $this->assertSame('telegram_business', $touch->integration_key);
        $this->assertNotNull($application->fresh()?->last_touch_at);
        $this->assertSame(1, IntegrationLog::query()->where('message', 'webhook_received')->count());
    }

    public function test_telegram_owner_message_is_outbound_and_follows_the_thread(): void
    {
        $this->telegram();
        $application = $this->applied($this->vacancyIn(Branch::factory()->create()), ['telegram_username' => 'cand_user']);
        $this->telegramWebhook($this->tgMessage(777, 777, 1, 'hello'))->assertOk();
        // The recruiter answers from their own phone: from = owner (999), chat = candidate (777), no username.
        $this->telegramWebhook($this->tgMessage(999, 777, 2, 'answer', null))->assertOk();

        $out = Touchpoint::query()->where('external_id', 'bc-1:2')->sole();
        $this->assertSame('out', $out->direction->value);
        $this->assertFalse($out->via_product);
        $this->assertSame($application->candidate_id, $out->candidate_id);
    }

    public function test_unmatched_telegram_message_goes_to_inbox(): void
    {
        $this->telegram(IntegrationStatus::Demo);
        $this->telegramWebhook($this->tgMessage(5, 5, 1, 'who is this', 'stranger_x'))->assertOk();

        $touch = Touchpoint::query()->sole();
        $this->assertNull($touch->candidate_id);
        $this->assertSame('@stranger_x', $touch->meta['contact'] ?? null);
    }

    public function test_telegram_connection_update_and_garbage_create_nothing(): void
    {
        $this->telegram();
        $this->telegramWebhook(['update_id' => 1, 'business_connection' => ['id' => 'bc-1', 'is_enabled' => true]])->assertOk();
        $this->telegramWebhook(['update_id' => 2, 'something_else' => ['x' => 1]])->assertOk();
        $this->telegramWebhook(['update_id' => 3, 'business_message' => 'not-an-object'])->assertOk();
        $this->assertSame(0, Touchpoint::query()->count());
        $this->assertSame(1, IntegrationLog::query()->where('message', 'business_connected')->count());
    }

    public function test_whatsapp_handshake(): void
    {
        $this->whatsapp();
        $this->get('/api/webhooks/whatsapp_cloud?hub.mode=subscribe&hub.verify_token='.self::WA_VERIFY.'&hub.challenge=1158201444')
            ->assertOk()->assertContent('1158201444');
        $this->get('/api/webhooks/whatsapp_cloud?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=1')->assertForbidden();
        $this->get('/api/webhooks/whatsapp_cloud?hub.mode=subscribe&hub.verify_token='.self::WA_VERIFY.'&hub.challenge=%3Cscript%3E')
            ->assertForbidden();
        $this->telegram();
        $this->get('/api/webhooks/telegram_business')->assertNotFound();
    }

    public function test_whatsapp_signature_matching_and_statuses(): void
    {
        $this->whatsapp();
        $application = $this->applied($this->vacancyIn(Branch::factory()->create()), ['phone' => '+380671234567']);

        $this->whatsappWebhook($this->waMessage('380671234567', 'wamid.A1', 'Привіт'), 'bad-secret')->assertForbidden();
        $this->whatsappWebhook($this->waMessage('380671234567', 'wamid.A1', 'Привіт'), null)->assertForbidden();
        $this->whatsappWebhook($this->waMessage('380671234567', 'wamid.A1', 'Привіт'))->assertOk();
        $this->whatsappWebhook($this->waMessage('380671234567', 'wamid.A1', 'Привіт'))->assertOk();
        // Another number of the same app is not ours.
        $this->whatsappWebhook($this->waMessage('380671234567', 'wamid.B1', 'x', '5550001'))->assertOk();

        $touch = Touchpoint::query()->where('channel', 'whatsapp')->sole();
        $this->assertSame($application->candidate_id, $touch->candidate_id);
        $this->assertSame('Привіт', $touch->body);
        $this->assertSame('Test Person', $touch->meta['sender_name'] ?? null);

        $statuses = ['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '1098765432'],
            'statuses' => [
                ['id' => 'wamid.X', 'status' => 'delivered', 'recipient_id' => '380671234567'],
                ['id' => 'wamid.Y', 'status' => 'failed', 'errors' => [['code' => 131047]]],
            ],
        ]]]]]];
        $this->whatsappWebhook($statuses)->assertOk();
        $this->assertSame(1, Touchpoint::query()->where('channel', 'whatsapp')->count());
        $this->assertSame(1, IntegrationLog::query()->where('message', 'delivery_failed')->count());
    }

    public function test_viber_message_lands_in_inbox_and_service_events_are_ignored(): void
    {
        $this->viber();
        $message = ['event' => 'message', 'timestamp' => now()->getTimestampMs(), 'message_token' => 4912661846655238145,
            'sender' => ['id' => 'viberUser01==', 'name' => 'Viber Person'], 'message' => ['type' => 'text', 'text' => 'Hi']];

        $this->viberWebhook($message, 'wrong')->assertForbidden();
        $this->viberWebhook($message)->assertOk()->assertExactJson(['status' => 0]);
        $this->viberWebhook(['event' => 'webhook', 'timestamp' => 1, 'message_token' => 1])->assertOk();
        $this->viberWebhook(['event' => 'delivered', 'message_token' => 2, 'user_id' => 'viberUser01=='])->assertOk();

        $touch = Touchpoint::query()->sole();
        $this->assertNull($touch->candidate_id);
        $this->assertSame('viberUser01==', $touch->meta['thread'] ?? null);
        $this->assertSame('4912661846655238145', $touch->external_id);
    }

    public function test_phonet_call_end_creates_call_touch(): void
    {
        $this->channel('phonet', IntegrationStatus::Demo, ['webhook_token' => self::PHONE_TOKEN], ['domain' => 'demo.example.test']);
        $application = $this->applied($this->vacancyIn(Branch::factory()->create()), ['phone' => '+380501112233']);
        $end = ['event' => 'call.hangup', 'uuid' => 'call-1', 'lgDirection' => 4,
            'otherLegs' => [['num' => '0501112233']], 'billSecs' => 125, 'callUrl' => 'https://records.example.test/1.mp3'];

        $this->postJson('/api/webhooks/phonet?token=wrong', $end)->assertForbidden();
        $this->postJson('/api/webhooks/phonet', $end)->assertForbidden();
        $this->postJson('/api/webhooks/phonet?token='.self::PHONE_TOKEN, ['event' => 'call.dial', 'uuid' => 'call-1'])->assertOk();
        $this->assertSame(0, Touchpoint::query()->where('channel', 'call')->count());
        $this->postJson('/api/webhooks/phonet?token='.self::PHONE_TOKEN, $end)->assertOk();
        $this->postJson('/api/webhooks/phonet?token='.self::PHONE_TOKEN, $end)->assertOk();

        $touch = Touchpoint::query()->where('channel', 'call')->sole();
        $this->assertSame($application->candidate_id, $touch->candidate_id);
        $this->assertSame(125, $touch->meta['duration_sec'] ?? null);
        $this->assertSame('https://records.example.test/1.mp3', $touch->meta['recording_url'] ?? null);
        $this->assertNull($touch->body);
    }

    public function test_binotel_form_payload_and_unsafe_recording_link(): void
    {
        $this->channel('binotel', IntegrationStatus::Demo, ['webhook_token' => self::PHONE_TOKEN]);
        $this->post('/api/webhooks/binotel?token='.self::PHONE_TOKEN, [
            'requestType' => 'apiCallCompleted',
            'callDetails' => ['generalCallID' => '555', 'callType' => '1', 'externalNumber' => '0931234567',
                'billsec' => '40', 'linkToCallRecordInMyBusiness' => 'javascript:alert(1)'],
        ])->assertOk()->assertExactJson(['status' => 'success']);

        $touch = Touchpoint::query()->sole();
        $this->assertSame('out', $touch->direction->value);
        $this->assertSame('0931234567', $touch->meta['contact'] ?? null);
        $this->assertArrayNotHasKey('recording_url', $touch->meta ?? []);
    }

    public function test_ringostat_configured_fields(): void
    {
        $this->channel('ringostat', IntegrationStatus::Demo, ['webhook_token' => self::PHONE_TOKEN], ['project_id' => '1']);
        $this->postJson('/api/webhooks/ringostat?token='.self::PHONE_TOKEN, [
            'uniqueid' => '1700000000.1', 'call_type' => 'in', 'caller' => '+380441112233', 'dst' => '380440000000',
            'calldate' => '2026-09-20 10:00:00', 'duration' => '61',
        ])->assertOk();

        $touch = Touchpoint::query()->sole();
        $this->assertSame('+380441112233', $touch->meta['contact'] ?? null);
        $this->assertSame(61, $touch->meta['duration_sec'] ?? null);
        $this->assertSame('2026-09-20', $touch->occurred_at->toDateString());
    }

    public function test_body_over_one_megabyte_is_refused(): void
    {
        $this->telegram();
        $big = (string) json_encode(['update_id' => 1, 'pad' => str_repeat('a', 1_048_600)]);
        $this->rawPost('/api/webhooks/telegram_business', $big, ['X-Telegram-Bot-Api-Secret-Token' => self::TG_SECRET])
            ->assertStatus(413);
    }

    public function test_logs_never_contain_secrets_or_payload(): void
    {
        $this->telegram();
        $this->telegramWebhook($this->tgMessage(5, 5, 1, 'secret-looking body text'))->assertOk();
        $this->telegramWebhook($this->tgMessage(5, 5, 2, 'x'), 'bad')->assertForbidden();

        $dump = (string) json_encode(IntegrationLog::query()->get()->toArray());
        $this->assertStringNotContainsString(self::TG_SECRET, $dump);
        $this->assertStringNotContainsString('secret-looking body text', $dump);
        $this->assertStringNotContainsString('bad', $dump);
    }
}
