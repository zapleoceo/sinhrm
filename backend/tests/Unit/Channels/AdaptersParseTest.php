<?php

declare(strict_types=1);

namespace Tests\Unit\Channels;

use App\Modules\Channels\Adapters\BinotelAdapter;
use App\Modules\Channels\Adapters\PhonetAdapter;
use App\Modules\Channels\Adapters\RingostatAdapter;
use App\Modules\Channels\Adapters\TelegramBusinessAdapter;
use App\Modules\Channels\Adapters\ViberAdapter;
use App\Modules\Channels\Adapters\WhatsappCloudAdapter;
use App\Modules\Channels\DTO\DemoSeed;
use App\Modules\Channels\Enums\EventKind;
use App\Modules\Channels\Support\Payload;
use App\Modules\Integrations\DTO\IntegrationConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Invented payloads only. */
final class AdaptersParseTest extends TestCase
{
    public function test_telegram_edited_and_media_messages(): void
    {
        $adapter = $this->app->make(TelegramBusinessAdapter::class);
        $events = $adapter->parse(['edited_business_message' => [
            'business_connection_id' => 'bc', 'message_id' => 7, 'edit_date' => 1_700_000_100, 'date' => 1_700_000_000,
            'from' => ['id' => 1, 'username' => 'Some_User'], 'chat' => ['id' => 1, 'username' => 'Some_User'],
        ]], $this->config());
        $this->assertCount(1, $events);
        $this->assertSame('bc:7:edit:1700000100', $events[0]->externalId);
        $this->assertTrue($events[0]->meta['edited']);
        $this->assertNull($events[0]->body);

        $photo = $adapter->parse(['business_message' => [
            'business_connection_id' => 'bc', 'message_id' => 8, 'date' => 1, 'photo' => [['file_id' => 'x']],
            'from' => ['id' => 2], 'chat' => ['id' => 1], 'sender_business_bot' => ['id' => 3],
        ]], $this->config());
        $this->assertSame('[photo]', $photo[0]->body);
        $this->assertSame('out', $photo[0]->direction->value);
    }

    public function test_telegram_verify_is_strict(): void
    {
        $adapter = $this->app->make(TelegramBusinessAdapter::class);
        $request = Request::create('/x', 'POST', server: ['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => 'abc']);
        $this->assertTrue($adapter->verify($request, $this->config(['webhook_secret' => 'abc'])));
        $this->assertFalse($adapter->verify($request, $this->config(['webhook_secret' => 'abcd'])));
        $this->assertFalse($adapter->verify($request, $this->config()));
    }

    public function test_whatsapp_interactive_reply_and_foreign_shapes(): void
    {
        $adapter = $this->app->make(WhatsappCloudAdapter::class);
        $events = $adapter->parse(['entry' => [['changes' => [['value' => ['messages' => [
            ['from' => '380931112233', 'id' => 'w1', 'type' => 'interactive', 'interactive' => ['button_reply' => ['title' => 'Так']]],
            ['from' => '380931112233', 'id' => 'w2', 'type' => 'image'],
            ['id' => 'no-from'],
        ]]]]]]], $this->config());
        $this->assertCount(2, $events);
        $this->assertSame('Так', $events[0]->body);
        $this->assertSame('+380931112233', $events[0]->contact);
        $this->assertSame('[image]', $events[1]->body);
        $this->assertSame([], $adapter->parse(['entry' => 'x'], $this->config()));
    }

    public function test_viber_signature_is_hmac_of_raw_body(): void
    {
        $adapter = $this->app->make(ViberAdapter::class);
        $body = '{"event":"message"}';
        $request = Request::create('/x', 'POST', server: ['HTTP_X_VIBER_CONTENT_SIGNATURE' => hash_hmac('sha256', $body, 'tok')], content: $body);
        $this->assertTrue($adapter->verify($request, $this->config(['token' => 'tok'])));
        $this->assertFalse($adapter->verify($request, $this->config(['token' => 'other'])));
    }

    public function test_telephony_direction_and_contact(): void
    {
        $phonet = $this->app->make(PhonetAdapter::class)->parse([
            'event' => 'call.hangup', 'uuid' => 'u1', 'lgDirection' => 2, 'otherLegs' => [['num' => '+380501110000']], 'duration' => 30,
        ], $this->config());
        $this->assertSame('out', $phonet[0]->direction->value);
        $this->assertSame('+380501110000', $phonet[0]->contact);
        $this->assertSame(EventKind::Call, $phonet[0]->kind);

        $ringostat = $this->app->make(RingostatAdapter::class)->parse([
            'uniqueid' => 'r1', 'call_type' => 'callback', 'caller' => '380440000000', 'dst' => '+380672223344',
        ], $this->config());
        $this->assertSame('out', $ringostat[0]->direction->value);
        $this->assertSame('+380672223344', $ringostat[0]->contact);
        $this->assertSame(0, $ringostat[0]->meta['duration_sec']);

        $binotel = $this->app->make(BinotelAdapter::class)->parse(['requestType' => 'receivedTheCall', 'callDetails' => ['generalCallID' => 'b1']], $this->config());
        $this->assertSame(EventKind::Status, $binotel[0]->kind);
        $this->assertSame([], $this->app->make(BinotelAdapter::class)->parse(['foo' => 'bar'], $this->config()));
    }

    public function test_demo_payloads_parse_back_to_one_event(): void
    {
        $seed = new DemoSeed('+380501234567', 'demo_user', 'Demo', 'text', Carbon::now(), 'abc', 123456789);
        foreach ([TelegramBusinessAdapter::class, WhatsappCloudAdapter::class, ViberAdapter::class, PhonetAdapter::class,
            RingostatAdapter::class, BinotelAdapter::class] as $class) {
            $adapter = $this->app->make($class);
            $events = $adapter->parse($adapter->demoPayload($seed, $this->config()), $this->config());
            $this->assertCount(1, $events, $class);
            $this->assertTrue($events[0]->kind->createsTouch(), $class);
            $this->assertNotNull($events[0]->externalId, $class);
        }
    }

    public function test_payload_helpers(): void
    {
        $now = Carbon::parse('2026-09-30 12:00:00');
        $this->assertSame('2026-09-30 11:00:00', Payload::time(['t' => 1_790_766_000], ['t'], $now)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-30 11:00:00', Payload::time(['t' => '1790766000000'], ['t'], $now)->format('Y-m-d H:i:s'));
        $this->assertTrue(Payload::time(['t' => '2099-01-01'], ['t'], $now)->equalTo($now));
        $this->assertTrue(Payload::time(['t' => 'not a date'], ['t'], $now)->equalTo($now));
        $this->assertSame('https://a.example.test/r.mp3', Payload::httpsUrl(['u' => 'https://a.example.test/r.mp3'], ['u']));
        $this->assertNull(Payload::httpsUrl(['u' => 'http://a.example.test/r.mp3'], ['u']));
        $this->assertNull(Payload::httpsUrl(['u' => 'javascript:alert(1)'], ['u']));
        $this->assertSame(12, Payload::int(['a' => ['b' => '12.4']], ['x', 'a.b']));
        $this->assertNull(Payload::str(['a' => ['nested']], ['a']));
    }

    /** @param  array<string, string>  $secrets */
    private function config(array $secrets = []): IntegrationConfig
    {
        return new IntegrationConfig('test', [], $secrets);
    }
}
