<?php

declare(strict_types=1);

namespace App\Modules\Channels\Adapters;

use App\Modules\Channels\Contracts\ChannelAdapter;
use App\Modules\Channels\Contracts\HandshakeResponder;
use App\Modules\Channels\Contracts\MessageSender;
use App\Modules\Channels\DTO\DemoSeed;
use App\Modules\Channels\DTO\IncomingEvent;
use App\Modules\Channels\DTO\OutgoingMessage;
use App\Modules\Channels\DTO\SentMessage;
use App\Modules\Channels\Enums\EventKind;
use App\Modules\Channels\Enums\WebhookAuth;
use App\Modules\Channels\Exceptions\ChannelException;
use App\Modules\Channels\Support\Payload;
use App\Modules\Channels\Support\ProviderHttp;
use App\Modules\Integrations\Definitions\WhatsappCloudDefinition;
use App\Modules\Integrations\DTO\IntegrationConfig;
use App\Modules\Recruiting\Enums\Channel;
use App\Modules\Recruiting\Enums\Direction;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * WhatsApp Cloud API. GET handshake: hub.mode=subscribe + hub.verify_token (vault "verify_token") → echo hub.challenge.
 * POST: X-Hub-Signature-256 = "sha256=" + HMAC-SHA256(raw body, vault "app_secret"). messages → touchpoints (contact
 * and thread = wa_id); statuses → counted (a failed delivery is logged by code). Sending: POST /{phone_number_id}/messages;
 * free text is allowed only within 24 h after the candidate's last message, otherwise → template_required.
 */
final readonly class WhatsappCloudAdapter implements ChannelAdapter, HandshakeResponder, MessageSender
{
    public const string SIGNATURE_HEADER = 'X-Hub-Signature-256';

    public const int WINDOW_HOURS = 24;

    /** Graph API error codes meaning "outside the customer service window / re-engagement required". */
    private const array TEMPLATE_ERRORS = [131047, 470];

    public function __construct(private ProviderHttp $http) {}

    public function key(): string
    {
        return 'whatsapp_cloud';
    }

    public function channel(): Channel
    {
        return Channel::Whatsapp;
    }

    public function auth(): WebhookAuth
    {
        return WebhookAuth::Hmac;
    }

    public function verify(Request $request, IntegrationConfig $config): bool
    {
        $secret = $config->secret('app_secret');
        $given = $request->header(self::SIGNATURE_HEADER);
        if ($secret === null || ! is_string($given)) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $given);
    }

    public function handshake(Request $request, IntegrationConfig $config): ?string
    {
        // PHP turns "hub.mode" into "hub_mode" when parsing the query string.
        $token = $config->secret('verify_token');
        $given = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');
        if ($token === null || $request->query('hub_mode') !== 'subscribe' || ! is_string($given) || ! is_string($challenge)
            || ! hash_equals($token, $given) || preg_match('/^[A-Za-z0-9_-]{1,128}$/', $challenge) !== 1) {
            return null;
        }

        return $challenge;
    }

    public function parse(array $payload, IntegrationConfig $config): array
    {
        $ownNumber = $config->setting('phone_number_id');
        $events = [];
        foreach (Payload::arr($payload['entry'] ?? null) as $entry) {
            foreach (Payload::arr(Payload::arr($entry)['changes'] ?? null) as $change) {
                $value = Payload::arr(Payload::arr($change)['value'] ?? null);
                $numberId = Payload::str($value, ['metadata.phone_number_id']);
                if ($ownNumber !== null && $numberId !== null && $numberId !== $ownNumber) {
                    continue;
                }
                $names = [];
                foreach (Payload::arr($value['contacts'] ?? null) as $contact) {
                    $waId = Payload::str(Payload::arr($contact), ['wa_id']);
                    if ($waId !== null) {
                        $names[$waId] = Payload::str(Payload::arr($contact), ['profile.name']);
                    }
                }
                foreach (Payload::arr($value['messages'] ?? null) as $message) {
                    $event = $this->message(Payload::arr($message), $names);
                    if ($event !== null) {
                        $events[] = $event;
                    }
                }
                foreach (Payload::arr($value['statuses'] ?? null) as $status) {
                    $status = Payload::arr($status);
                    $events[] = new IncomingEvent(EventKind::Status, Direction::Out, Carbon::now(), meta: array_filter([
                        'status' => Payload::str($status, ['status']),
                        'error_code' => Payload::int($status, ['errors.0.code']),
                    ], static fn (mixed $v): bool => $v !== null));
                }
            }
        }

        return $events;
    }

    public function demoPayload(DemoSeed $seed, IntegrationConfig $config): array
    {
        $waId = ltrim($seed->phone ?? '+380500000000', '+');

        return ['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => 'demo',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => ['display_phone_number' => '380000000000', 'phone_number_id' => $config->setting('phone_number_id') ?? 'demo'],
                    'contacts' => [['profile' => ['name' => $seed->senderName], 'wa_id' => $waId]],
                    'messages' => [[
                        'from' => $waId,
                        'id' => 'wamid.demo-'.$seed->uid,
                        'timestamp' => (string) $seed->at->getTimestamp(),
                        'type' => 'text',
                        'text' => ['body' => $seed->text],
                    ]],
                ],
            ]],
        ]]];
    }

    public function acknowledge(): array
    {
        return ['ok' => true];
    }

    public function send(OutgoingMessage $message, IntegrationConfig $config): SentMessage
    {
        $to = $this->digits($message->recipient->thread ?? $message->recipient->phone);
        $last = $message->recipient->lastInboundAt;
        if ($last === null || $last->lessThan($message->now->copy()->subHours(self::WINDOW_HOURS))) {
            throw ChannelException::templateRequired();
        }

        return new SentMessage($this->post($config, $to, $message->text), $to);
    }

    public function sendTest(string $to, string $text, IntegrationConfig $config): SentMessage
    {
        return new SentMessage($this->post($config, $this->digits($to), $text));
    }

    private function post(IntegrationConfig $config, string $to, string $text): string
    {
        $numberId = (string) $config->setting('phone_number_id');
        $token = $config->secret('access_token');
        if ($token === null || preg_match(WhatsappCloudDefinition::ID_PATTERN, $numberId) !== 1) {
            throw ChannelException::notConnected();
        }
        $response = $this->http->postJson(WhatsappCloudDefinition::GRAPH_API.'/'.$numberId.'/messages', [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $text, 'preview_url' => false],
        ], bearer: $token);
        $id = $response->json('messages.0.id');
        if ($response->successful() && is_string($id)) {
            return $id;
        }
        if (in_array($response->json('error.code'), self::TEMPLATE_ERRORS, true)) {
            throw ChannelException::templateRequired();
        }

        throw ChannelException::sendFailed();
    }

    /** E.164 without "+" (the Cloud API "to" format). */
    private function digits(?string $phone): string
    {
        $digits = (string) preg_replace('/\D+/', '', (string) $phone);
        if (strlen($digits) < 8 || strlen($digits) > 15) {
            throw ChannelException::invalidRecipient();
        }

        return $digits;
    }

    /**
     * @param  array<array-key, mixed>  $message
     * @param  array<string, string|null>  $names
     */
    private function message(array $message, array $names): ?IncomingEvent
    {
        $from = Payload::str($message, ['from']);
        $id = Payload::str($message, ['id']);
        if ($from === null || $id === null) {
            return null;
        }
        $type = Payload::str($message, ['type']) ?? 'unknown';
        $body = Payload::str($message, ['text.body', 'button.text', 'interactive.button_reply.title',
            'interactive.list_reply.title', 'image.caption', 'document.caption', 'video.caption']) ?? '['.$type.']';

        return new IncomingEvent(
            kind: EventKind::Message,
            direction: Direction::In,
            occurredAt: Payload::time($message, ['timestamp'], Carbon::now()),
            contact: '+'.ltrim($from, '+'),
            body: $body,
            externalId: $id,
            thread: ltrim($from, '+'),
            meta: array_filter(['sender_name' => $names[$from] ?? null], static fn (mixed $v): bool => $v !== null),
        );
    }
}
