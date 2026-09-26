<?php

declare(strict_types=1);

namespace App\Modules\Channels\Adapters;

use App\Modules\Channels\DTO\DemoSeed;
use App\Modules\Integrations\DTO\IntegrationConfig;
use App\Modules\Recruiting\Enums\Direction;

/**
 * Phonet (PROVISIONAL mapping from public docs): JSON events "call.dial" / "call.bridge" / "call.hangup";
 * uuid = call id; lgDirection 2 = outgoing (other values → incoming); otherLegs[0].num = the client's number;
 * billSecs / duration = talk time; callUrl / recordUrl = recording link. Only "call.hangup" creates a touchpoint.
 * Click-to-call is not implemented for Phonet (→ click_to_call_unsupported).
 */
final class PhonetAdapter extends AbstractTelephonyAdapter
{
    private const array END_EVENTS = ['call.hangup', 'hangup', 'call.end', 'call_end'];

    public function key(): string
    {
        return 'phonet';
    }

    public function demoPayload(DemoSeed $seed, IntegrationConfig $config): array
    {
        return [
            'event' => 'call.hangup',
            'uuid' => 'demo-'.$seed->uid,
            'lgDirection' => 4,
            'leg' => ['ext' => '101', 'displayName' => 'Recruiter'],
            'otherLegs' => [['num' => $seed->phone ?? '+380500000000', 'displayName' => $seed->senderName]],
            'dialAt' => $seed->at->getTimestampMs(),
            'billSecs' => 95,
            'callUrl' => null,
        ];
    }

    protected function isCallEnd(array $payload): bool
    {
        $event = is_string($payload['event'] ?? null) ? mb_strtolower($payload['event']) : null;

        return in_array($event, self::END_EVENTS, true);
    }

    protected function direction(array $payload): Direction
    {
        $value = $payload['lgDirection'] ?? $payload['direction'] ?? null;

        return self::directionOf(is_scalar($value) ? (string) $value : null, ['2', 'out', 'outgoing', 'outbound']);
    }

    protected function idKeys(): array
    {
        return ['uuid', 'callId', 'call_id', 'id'];
    }

    protected function callerKeys(): array
    {
        return ['otherLegs.0.num', 'otherLegs.0.number', 'from', 'caller'];
    }

    protected function calleeKeys(): array
    {
        return ['otherLegs.0.num', 'otherLegs.0.number', 'to', 'callee'];
    }

    protected function durationKeys(): array
    {
        return ['billSecs', 'billsec', 'duration', 'talkDuration'];
    }

    protected function recordingKeys(): array
    {
        return ['callUrl', 'recordUrl', 'recording_url', 'record'];
    }

    protected function startKeys(): array
    {
        return ['dialAt', 'bridgeAt', 'serverTime', 'start'];
    }
}
