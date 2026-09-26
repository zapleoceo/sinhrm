<?php

declare(strict_types=1);

namespace App\Modules\Channels\Adapters;

use App\Modules\Channels\Contracts\ChannelAdapter;
use App\Modules\Channels\DTO\IncomingEvent;
use App\Modules\Channels\Enums\EventKind;
use App\Modules\Channels\Enums\WebhookAuth;
use App\Modules\Channels\Support\Payload;
use App\Modules\Integrations\DTO\IntegrationConfig;
use App\Modules\Recruiting\Enums\Channel;
use App\Modules\Recruiting\Enums\Direction;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Telephony webhooks (PROVISIONAL: payload formats are not confirmed on real accounts). Auth: ?token= compared in
 * constant time with vault "webhook_token". Only the call-end event creates a touchpoint (channel call, duration,
 * recording link in meta — never downloaded). Mapping is tolerant: each provider lists the keys it may send.
 * No transcript yet, so script evaluation skips these calls until speech-to-text (Deepgram) is connected.
 */
abstract class AbstractTelephonyAdapter implements ChannelAdapter
{
    public function channel(): Channel
    {
        return Channel::Call;
    }

    public function auth(): WebhookAuth
    {
        return WebhookAuth::QueryToken;
    }

    public function verify(Request $request, IntegrationConfig $config): bool
    {
        $token = $config->secret('webhook_token');
        $given = $request->query('token');

        return $token !== null && is_string($given) && hash_equals($token, $given);
    }

    public function parse(array $payload, IntegrationConfig $config): array
    {
        $id = Payload::str($payload, $this->idKeys());
        if ($id === null || ! $this->isCallEnd($payload)) {
            return $id === null ? [] : [new IncomingEvent(EventKind::Status, Direction::In, Carbon::now(), meta: ['status' => 'call_progress'])];
        }
        $direction = $this->direction($payload);
        $contact = Payload::str($payload, $direction === Direction::In ? $this->callerKeys() : $this->calleeKeys())
            ?? Payload::str($payload, $this->externalKeys());

        return [new IncomingEvent(
            kind: EventKind::Call,
            direction: $direction,
            occurredAt: Payload::time($payload, $this->startKeys(), Carbon::now()),
            contact: $contact,
            body: null,
            // Prefixed with the provider key: three telephony providers share the "call" channel, ids may collide.
            externalId: $this->key().':'.$id,
            meta: array_filter([
                'duration_sec' => Payload::int($payload, $this->durationKeys()) ?? 0,
                'recording_url' => Payload::httpsUrl($payload, $this->recordingKeys()),
                'call_status' => Payload::str($payload, $this->statusKeys()),
            ], static fn (mixed $v): bool => $v !== null),
        )];
    }

    public function acknowledge(): array
    {
        return ['ok' => true];
    }

    /** @param  array<array-key, mixed>  $payload */
    abstract protected function isCallEnd(array $payload): bool;

    /** @param  array<array-key, mixed>  $payload */
    abstract protected function direction(array $payload): Direction;

    /** @return list<string> */
    abstract protected function idKeys(): array;

    /** @return list<string> number of the calling party */
    abstract protected function callerKeys(): array;

    /** @return list<string> number of the called party */
    abstract protected function calleeKeys(): array;

    /** @return list<string> "the client's number" fields, whatever the direction */
    protected function externalKeys(): array
    {
        return [];
    }

    /** @return list<string> */
    abstract protected function durationKeys(): array;

    /** @return list<string> */
    abstract protected function recordingKeys(): array;

    /** @return list<string> */
    abstract protected function startKeys(): array;

    /** @return list<string> */
    protected function statusKeys(): array
    {
        return ['disposition', 'status', 'callStatus'];
    }

    /**
     * Normalized direction words; unknown → incoming (the safer guess for a call from a candidate).
     *
     * @param  list<string>  $out  values that mean an outgoing call
     */
    protected static function directionOf(?string $value, array $out): Direction
    {
        return $value !== null && in_array(mb_strtolower($value), $out, true) ? Direction::Out : Direction::In;
    }
}
