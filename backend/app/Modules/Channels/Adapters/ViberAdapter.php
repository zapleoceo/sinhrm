<?php

declare(strict_types=1);

namespace App\Modules\Channels\Adapters;

use App\Modules\Channels\Contracts\ChannelAdapter;
use App\Modules\Channels\Contracts\MessageSender;
use App\Modules\Channels\Contracts\WebhookRegistrar;
use App\Modules\Channels\DTO\DemoSeed;
use App\Modules\Channels\DTO\IncomingEvent;
use App\Modules\Channels\DTO\OutgoingMessage;
use App\Modules\Channels\DTO\SentMessage;
use App\Modules\Channels\Enums\EventKind;
use App\Modules\Channels\Enums\WebhookAuth;
use App\Modules\Channels\Exceptions\ChannelException;
use App\Modules\Channels\Support\Payload;
use App\Modules\Channels\Support\ProviderHttp;
use App\Modules\Integrations\Definitions\ViberDefinition;
use App\Modules\Integrations\DTO\IntegrationConfig;
use App\Modules\Recruiting\Enums\Channel;
use App\Modules\Recruiting\Enums\Direction;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Viber REST Bot API. Webhook auth: X-Viber-Content-Signature = hex HMAC-SHA256(raw body, bot token).
 * Event "message" → touchpoint (Viber does not share the phone: contact = none, thread = sender.id, so the first
 * message lands in the inbox and, once linked, later ones follow the candidate). Sending: /pa/send_message to the
 * sender.id of the conversation. Registration: /pa/set_webhook (Viber immediately calls the URL with event "webhook").
 */
final readonly class ViberAdapter implements ChannelAdapter, MessageSender, WebhookRegistrar
{
    public const string SIGNATURE_HEADER = 'X-Viber-Content-Signature';

    private const string SENDER_NAME = 'SinHRM';

    /** Viber status 5/6 = receiver not registered / not subscribed. */
    private const array NO_CONVERSATION = [5, 6];

    public function __construct(private ProviderHttp $http) {}

    public function key(): string
    {
        return 'viber';
    }

    public function channel(): Channel
    {
        return Channel::Viber;
    }

    public function auth(): WebhookAuth
    {
        return WebhookAuth::Hmac;
    }

    public function verify(Request $request, IntegrationConfig $config): bool
    {
        $token = $config->secret('token');
        $given = $request->header(self::SIGNATURE_HEADER);

        return $token !== null && is_string($given)
            && hash_equals(hash_hmac('sha256', $request->getContent(), $token), strtolower($given));
    }

    public function parse(array $payload, IntegrationConfig $config): array
    {
        $event = Payload::str($payload, ['event']);
        if ($event !== 'message') {
            return $event === null ? [] : [new IncomingEvent(EventKind::Status, Direction::In, Carbon::now(), meta: ['status' => $event])];
        }
        $senderId = Payload::str($payload, ['sender.id']);
        $token = Payload::str($payload, ['message_token']);
        if ($senderId === null || $token === null) {
            return [];
        }
        $type = Payload::str($payload, ['message.type']) ?? 'unknown';

        return [new IncomingEvent(
            kind: EventKind::Message,
            direction: Direction::In,
            occurredAt: Payload::time($payload, ['timestamp'], Carbon::now()),
            contact: null,
            body: Payload::str($payload, ['message.text']) ?? '['.$type.']',
            externalId: $token,
            thread: $senderId,
            meta: array_filter(['sender_name' => Payload::str($payload, ['sender.name'])], static fn (mixed $v): bool => $v !== null),
        )];
    }

    public function demoPayload(DemoSeed $seed, IntegrationConfig $config): array
    {
        return [
            'event' => 'message',
            'timestamp' => $seed->at->getTimestampMs(),
            'message_token' => (string) $seed->numericId,
            'sender' => ['id' => 'demo-'.$seed->uid, 'name' => $seed->senderName, 'language' => 'uk', 'country' => 'UA'],
            'message' => ['type' => 'text', 'text' => $seed->text],
        ];
    }

    public function acknowledge(): array
    {
        return ['status' => 0];
    }

    public function send(OutgoingMessage $message, IntegrationConfig $config): SentMessage
    {
        $receiver = $message->recipient->thread ?? throw ChannelException::noConversation();

        return new SentMessage($this->post($config, $receiver, $message->text), $receiver);
    }

    public function sendTest(string $to, string $text, IntegrationConfig $config): SentMessage
    {
        if (preg_match('/^[A-Za-z0-9+\/=_-]{4,64}$/', $to) !== 1) {
            throw ChannelException::invalidRecipient();
        }

        return new SentMessage('test:'.$this->post($config, $to, $text));
    }

    public function generatedSecret(): ?string
    {
        return null;
    }

    public function register(string $url, ?string $secret, IntegrationConfig $config): void
    {
        $response = $this->call($config, 'set_webhook', [
            'url' => $url,
            'event_types' => ['delivered', 'seen', 'failed', 'subscribed', 'unsubscribed', 'conversation_started'],
            'send_name' => true,
            'send_photo' => false,
        ]);
        if ($response !== 0) {
            throw ChannelException::sendFailed();
        }
    }

    private function post(IntegrationConfig $config, string $receiver, string $text): string
    {
        $token = $config->secret('token') ?? throw ChannelException::notConnected();
        $response = $this->http->postJson(ViberDefinition::API.'/send_message', [
            'receiver' => $receiver,
            'type' => 'text',
            'text' => $text,
            'sender' => ['name' => self::SENDER_NAME],
        ], ['X-Viber-Auth-Token' => $token]);
        $status = $response->json('status');
        if ($response->successful() && $status === 0) {
            return (string) $response->json('message_token');
        }

        throw in_array($status, self::NO_CONVERSATION, true) ? ChannelException::noConversation() : ChannelException::sendFailed();
    }

    /** @param  array<string, mixed>  $body */
    private function call(IntegrationConfig $config, string $method, array $body): mixed
    {
        $token = $config->secret('token') ?? throw ChannelException::notConnected();
        $response = $this->http->postJson(ViberDefinition::API.'/'.$method, $body, ['X-Viber-Auth-Token' => $token]);

        return $response->successful() ? $response->json('status') : null;
    }
}
