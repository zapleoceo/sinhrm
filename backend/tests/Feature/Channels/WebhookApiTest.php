<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Modules\Channels\Http\Controllers\WebhookController;
use App\Modules\Directory\Models\Branch;
use App\Modules\Integrations\Enums\IntegrationStatus;
use App\Modules\Integrations\Models\IntegrationLog;
use App\Modules\Observability\Models\ErrorEvent;
use App\Modules\Recruiting\Models\Touchpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
        $this->telegramWebhook($this->tgMessage(5, 5, 1, 'hi'), 'wrong-secret')->assertUnauthorized();
        $this->postJson('/api/webhooks/telegram_business', $this->tgMessage(5, 5, 1, 'hi'))->assertUnauthorized();
        $this->assertSame(0, Touchpoint::query()->count());
        $this->assertSame(2, IntegrationLog::query()->where('message', 'webhook_rejected')->count());
    }

    public function test_secret_not_configured_rejects_everything(): void
    {
        $this->channel('telegram_business', IntegrationStatus::Demo, ['bot_token' => self::TG_BOT_TOKEN]);
        $this->telegramWebhook($this->tgMessage(5, 5, 1, 'hi'), '')->assertUnauthorized();
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

        $this->whatsappWebhook($this->waMessage('380671234567', 'wamid.A1', 'Привіт'), 'bad-secret')->assertUnauthorized();
        $this->whatsappWebhook($this->waMessage('380671234567', 'wamid.A1', 'Привіт'), null)->assertUnauthorized();
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

        $this->viberWebhook($message, 'wrong')->assertUnauthorized();
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

        $this->postJson('/api/webhooks/phonet', $end, ['X-Webhook-Token' => 'wrong'])->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer realm="webhooks"');
        $this->postJson('/api/webhooks/phonet', $end)->assertUnauthorized();
        $this->postJson('/api/webhooks/phonet', ['event' => 'call.dial', 'uuid' => 'call-1'], $this->phoneAuth())->assertOk();
        $this->assertSame(0, Touchpoint::query()->where('channel', 'call')->count());
        $this->postJson('/api/webhooks/phonet', $end, $this->phoneAuth())->assertOk()->assertHeaderMissing('Deprecation');
        $this->postJson('/api/webhooks/phonet', $end, $this->phoneAuth())->assertOk();

        $touch = Touchpoint::query()->where('channel', 'call')->sole();
        $this->assertSame($application->candidate_id, $touch->candidate_id);
        $this->assertSame(125, $touch->meta['duration_sec'] ?? null);
        $this->assertSame('https://records.example.test/1.mp3', $touch->meta['recording_url'] ?? null);
        $this->assertNull($touch->body);
        $this->assertSame('phonet:call-1', $touch->external_id);
    }

    /** HRM-26: every header form of the telephony token is accepted; anything else is 401. */
    public function test_telephony_token_in_header_bearer_or_body_signature(): void
    {
        $this->channel('ringostat', IntegrationStatus::Demo, ['webhook_token' => self::PHONE_TOKEN], ['project_id' => '1']);
        $call = fn (string $id): string => (string) json_encode(['uniqueid' => $id, 'call_type' => 'in', 'caller' => '+380441112233', 'duration' => 5]);

        $this->rawPost('/api/webhooks/ringostat', $call('h1'), ['X-Webhook-Token' => self::PHONE_TOKEN])->assertOk();
        $this->rawPost('/api/webhooks/ringostat', $call('h2'), ['Authorization' => 'Bearer '.self::PHONE_TOKEN])->assertOk();
        $body = $call('h3');
        $this->rawPost('/api/webhooks/ringostat', $body, ['X-Signature' => hash_hmac('sha256', $body, self::PHONE_TOKEN)])->assertOk();
        $body = $call('h4');
        $this->rawPost('/api/webhooks/ringostat', $body, ['X-Signature' => 'sha256='.hash_hmac('sha256', $body, self::PHONE_TOKEN)])->assertOk();

        $this->rawPost('/api/webhooks/ringostat', $call('x1'), ['X-Webhook-Token' => 'fake-wrong-token'])->assertUnauthorized();
        $this->rawPost('/api/webhooks/ringostat', $call('x2'), ['Authorization' => 'Bearer fake-wrong-token'])->assertUnauthorized();
        // A signature of another body (replayed header) does not match.
        $this->rawPost('/api/webhooks/ringostat', $call('x3'), ['X-Signature' => hash_hmac('sha256', $call('h3'), self::PHONE_TOKEN)])->assertUnauthorized();
        $this->rawPost('/api/webhooks/ringostat', $call('x4'))->assertUnauthorized();

        $this->assertEqualsCanonicalizing(['ringostat:h1', 'ringostat:h2', 'ringostat:h3', 'ringostat:h4'],
            Touchpoint::query()->where('channel', 'call')->pluck('external_id')->all());
        $this->assertSame(4, IntegrationLog::query()->where('message', 'webhook_rejected')->count());
    }

    public function test_telephony_without_configured_token_rejects_everything(): void
    {
        $this->channel('binotel', IntegrationStatus::Demo, [], ['webhook_query_token' => 'on']);
        $this->post('/api/webhooks/binotel', ['requestType' => 'apiCallCompleted'], ['X-Webhook-Token' => ''])->assertUnauthorized();
        $this->post('/api/webhooks/binotel?token=', ['requestType' => 'apiCallCompleted'])->assertUnauthorized();
        $this->assertSame(0, Touchpoint::query()->count());
    }

    /** Flag off (the default for new connections): ?token= is refused even when correct — and even next to a valid header. */
    public function test_query_token_is_401_while_the_transitional_flag_is_off(): void
    {
        $this->channel('phonet', IntegrationStatus::Demo, ['webhook_token' => self::PHONE_TOKEN], ['domain' => 'demo.example.test']);
        $end = ['event' => 'call.hangup', 'uuid' => 'q-1', 'lgDirection' => 4, 'otherLegs' => [['num' => '0501112233']], 'billSecs' => 3];

        $this->postJson('/api/webhooks/phonet?token='.self::PHONE_TOKEN, $end)->assertUnauthorized();
        $this->postJson('/api/webhooks/phonet?token='.self::PHONE_TOKEN, $end, $this->phoneAuth())->assertUnauthorized();

        $this->assertSame(0, Touchpoint::query()->count());
        $rejected = IntegrationLog::query()->where('message', 'webhook_rejected')->get();
        $this->assertCount(2, $rejected);
        $this->assertSame('query_token_disabled', $rejected[0]->context['reason'] ?? null);
    }

    /** Flag on (existing connections, transitional): ?token= is accepted, the answer and the log mark it deprecated. */
    public function test_query_token_is_accepted_and_marked_deprecated_while_the_flag_is_on(): void
    {
        $this->channel('phonet', IntegrationStatus::Demo, ['webhook_token' => self::PHONE_TOKEN], ['domain' => 'demo.example.test', 'webhook_query_token' => 'on']);
        $end = ['event' => 'call.hangup', 'uuid' => 'q-2', 'lgDirection' => 4, 'otherLegs' => [['num' => '0501112233']], 'billSecs' => 3];

        $this->postJson('/api/webhooks/phonet?token=wrong', $end)->assertUnauthorized();
        $this->postJson('/api/webhooks/phonet?token='.self::PHONE_TOKEN, $end)->assertOk()
            ->assertHeader('Deprecation', WebhookController::DEPRECATED_SINCE);
        // The header way keeps working next to the flag and is not marked.
        $this->postJson('/api/webhooks/phonet', [...$end, 'uuid' => 'q-3'], $this->phoneAuth())->assertOk()->assertHeaderMissing('Deprecation');

        $this->assertSame(2, Touchpoint::query()->where('channel', 'call')->count());
        $received = IntegrationLog::query()->where('message', 'webhook_received')->orderBy('id')->get();
        $this->assertSame('warning', $received[0]->level->value);
        $this->assertSame('query_token_deprecated', $received[0]->context['auth'] ?? null);
        $this->assertSame('info', $received[1]->level->value);
        $this->assertArrayNotHasKey('auth', $received[1]->context ?? []);
    }

    /** Neither the integration log, the error log nor the application log gets the token, wherever it was sent. */
    public function test_telephony_token_never_reaches_any_log(): void
    {
        $log = Log::spy();
        $this->channel('phonet', IntegrationStatus::Demo, ['webhook_token' => self::PHONE_TOKEN], ['domain' => 'demo.example.test']);
        $end = ['event' => 'call.hangup', 'uuid' => 'l-1', 'lgDirection' => 4, 'otherLegs' => [['num' => '0501112233']], 'billSecs' => 3];

        $this->postJson('/api/webhooks/phonet?token='.self::PHONE_TOKEN, $end)->assertUnauthorized();
        $this->postJson('/api/webhooks/phonet?token=fake-guess-0007', $end)->assertUnauthorized();
        $this->postJson('/api/webhooks/phonet', $end, ['X-Webhook-Token' => 'fake-guess-0008'])->assertUnauthorized();
        $this->postJson('/api/webhooks/phonet', $end, $this->phoneAuth())->assertOk();

        $dump = json_encode(IntegrationLog::query()->get()->toArray()).json_encode(ErrorEvent::query()->get()->toArray());
        foreach ([self::PHONE_TOKEN, 'fake-guess-0007', 'fake-guess-0008', 'token='] as $needle) {
            $this->assertStringNotContainsString($needle, $dump);
        }
        $log->shouldNotHaveReceived('error');
        $log->shouldNotHaveReceived('warning');
    }

    public function test_same_call_id_from_two_providers_creates_two_touches(): void
    {
        $this->channel('phonet', IntegrationStatus::Demo, ['webhook_token' => self::PHONE_TOKEN], ['domain' => 'demo.example.test']);
        $this->channel('binotel', IntegrationStatus::Demo, ['webhook_token' => self::PHONE_TOKEN]);

        $this->postJson('/api/webhooks/phonet', ['event' => 'call.hangup', 'uuid' => '555', 'lgDirection' => 4,
            'otherLegs' => [['num' => '0501112233']], 'billSecs' => 10], $this->phoneAuth())->assertOk();
        $this->post('/api/webhooks/binotel', ['requestType' => 'apiCallCompleted',
            'callDetails' => ['generalCallID' => '555', 'callType' => '1', 'externalNumber' => '0931234567', 'billsec' => '40']], $this->phoneAuth())->assertOk();

        $this->assertEqualsCanonicalizing(['phonet:555', 'binotel:555'],
            Touchpoint::query()->where('channel', 'call')->pluck('external_id')->all());
    }

    public function test_binotel_form_payload_and_unsafe_recording_link(): void
    {
        $this->channel('binotel', IntegrationStatus::Demo, ['webhook_token' => self::PHONE_TOKEN]);
        $this->post('/api/webhooks/binotel', [
            'requestType' => 'apiCallCompleted',
            'callDetails' => ['generalCallID' => '555', 'callType' => '1', 'externalNumber' => '0931234567',
                'billsec' => '40', 'linkToCallRecordInMyBusiness' => 'javascript:alert(1)'],
        ], ['Authorization' => 'Bearer '.self::PHONE_TOKEN])->assertOk()->assertExactJson(['status' => 'success']);

        $touch = Touchpoint::query()->sole();
        $this->assertSame('out', $touch->direction->value);
        $this->assertSame('0931234567', $touch->meta['contact'] ?? null);
        $this->assertArrayNotHasKey('recording_url', $touch->meta ?? []);
    }

    public function test_ringostat_configured_fields(): void
    {
        $this->channel('ringostat', IntegrationStatus::Demo, ['webhook_token' => self::PHONE_TOKEN], ['project_id' => '1']);
        $this->postJson('/api/webhooks/ringostat', [
            'uniqueid' => '1700000000.1', 'call_type' => 'in', 'caller' => '+380441112233', 'dst' => '380440000000',
            'calldate' => '2026-09-20 10:00:00', 'duration' => '61',
        ], $this->phoneAuth())->assertOk();

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
        $this->telegramWebhook($this->tgMessage(5, 5, 2, 'x'), 'bad')->assertUnauthorized();

        $dump = (string) json_encode(IntegrationLog::query()->get()->toArray());
        $this->assertStringNotContainsString(self::TG_SECRET, $dump);
        $this->assertStringNotContainsString('secret-looking body text', $dump);
        $this->assertStringNotContainsString('bad', $dump);
    }
}
