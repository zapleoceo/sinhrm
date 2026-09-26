<?php

declare(strict_types=1);

namespace App\Modules\Channels\Adapters;

use App\Modules\Channels\Contracts\CallInitiator;
use App\Modules\Channels\DTO\DemoSeed;
use App\Modules\Channels\Exceptions\ChannelException;
use App\Modules\Channels\Support\ProviderHttp;
use App\Modules\Integrations\DTO\IntegrationConfig;
use App\Modules\Recruiting\Enums\Direction;

/**
 * Ringostat (PROVISIONAL). Webhook fields are chosen by the owner in Ringostat ("Integrations → Webhooks"); the
 * recommended set (docs/modules/channels.md): uniqueid, call_type (in/out/callback), caller, dst, duration (talk
 * seconds), recording, calldate, disposition, event=call_end. Common alternative names are accepted too.
 * Click-to-call: POST https://api.ringostat.net/callback/outward_call (header Auth-key = api_key, form extension +
 * destination), behind the SSRF guard — request format not confirmed on a real account.
 */
final class RingostatAdapter extends AbstractTelephonyAdapter implements CallInitiator
{
    public const string CALLBACK_URL = 'https://api.ringostat.net/callback/outward_call';

    private const array END_EVENTS = ['call_end', 'call-end', 'end', 'hangup', 'completed'];

    public function __construct(private readonly ProviderHttp $http) {}

    public function key(): string
    {
        return 'ringostat';
    }

    public function demoPayload(DemoSeed $seed, IntegrationConfig $config): array
    {
        return [
            'event' => 'call_end',
            'uniqueid' => 'demo-'.$seed->uid,
            'call_type' => 'in',
            'caller' => $seed->phone ?? '+380500000000',
            'dst' => '380440000000',
            'calldate' => $seed->at->format('Y-m-d H:i:s'),
            'duration' => 72,
            'disposition' => 'ANSWERED',
        ];
    }

    public function call(string $phone, IntegrationConfig $config): void
    {
        $key = $config->secret('api_key');
        $extension = $config->setting('callback_extension');
        if ($key === null || $extension === null) {
            throw ChannelException::telephonyNotConnected();
        }
        $response = $this->http->postForm(self::CALLBACK_URL, [
            'extension' => $extension,
            'destination' => ltrim($phone, '+'),
        ], ['Auth-key' => $key]);
        if (! $response->successful()) {
            throw ChannelException::sendFailed();
        }
    }

    protected function isCallEnd(array $payload): bool
    {
        $event = is_string($payload['event'] ?? null) ? mb_strtolower($payload['event']) : null;

        // Ringostat sends the webhook the owner configured; without an event field it is the "call ended" hook.
        return $event === null || in_array($event, self::END_EVENTS, true);
    }

    protected function direction(array $payload): Direction
    {
        $value = $payload['call_type'] ?? $payload['direction'] ?? null;

        return self::directionOf(is_scalar($value) ? (string) $value : null, ['out', 'outgoing', 'callback', 'outbound']);
    }

    protected function idKeys(): array
    {
        return ['uniqueid', 'call_id', 'callId', 'id'];
    }

    protected function callerKeys(): array
    {
        return ['caller', 'src', 'from', 'client_number'];
    }

    protected function calleeKeys(): array
    {
        return ['dst', 'destination', 'to', 'client_number'];
    }

    protected function durationKeys(): array
    {
        return ['billsec', 'duration', 'talk_time'];
    }

    protected function recordingKeys(): array
    {
        return ['recording', 'recording_url', 'record_url', 'record'];
    }

    protected function startKeys(): array
    {
        return ['calldate', 'call_date', 'start_time', 'timestamp'];
    }
}
