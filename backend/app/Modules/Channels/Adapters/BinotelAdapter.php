<?php

declare(strict_types=1);

namespace App\Modules\Channels\Adapters;

use App\Modules\Channels\DTO\DemoSeed;
use App\Modules\Integrations\DTO\IntegrationConfig;
use App\Modules\Recruiting\Enums\Direction;

/**
 * Binotel (PROVISIONAL mapping from public docs): "API CALL COMPLETED" form POST with requestType=apiCallCompleted and
 * callDetails[...]: generalCallID, callType (0 incoming / 1 outgoing), externalNumber (the client), billsec,
 * startTime (unix), linkToCallRecordInMyBusiness. Binotel expects {"status":"success"} in answer.
 * Click-to-call is not implemented for Binotel (→ click_to_call_unsupported).
 */
final class BinotelAdapter extends AbstractTelephonyAdapter
{
    public function key(): string
    {
        return 'binotel';
    }

    public function acknowledge(): array
    {
        return ['status' => 'success'];
    }

    public function demoPayload(DemoSeed $seed, IntegrationConfig $config): array
    {
        return [
            'requestType' => 'apiCallCompleted',
            'callDetails' => [
                'generalCallID' => 'demo-'.$seed->uid,
                'callType' => '0',
                'internalNumber' => '901',
                'externalNumber' => $seed->phone ?? '0500000000',
                'startTime' => (string) $seed->at->getTimestamp(),
                'billsec' => '140',
                'disposition' => 'ANSWER',
            ],
        ];
    }

    protected function isCallEnd(array $payload): bool
    {
        $type = $payload['requestType'] ?? null;

        // No requestType at all (another export format) but a duration → treat as a completed call.
        return $type === 'apiCallCompleted' || ($type === null && isset($payload['callDetails']['billsec']));
    }

    protected function direction(array $payload): Direction
    {
        $value = $payload['callDetails']['callType'] ?? null;

        return self::directionOf(is_scalar($value) ? (string) $value : null, ['1', 'out', 'outgoing']);
    }

    protected function idKeys(): array
    {
        return ['callDetails.generalCallID', 'callDetails.callID', 'generalCallID'];
    }

    protected function callerKeys(): array
    {
        return ['callDetails.externalNumber'];
    }

    protected function calleeKeys(): array
    {
        return ['callDetails.externalNumber'];
    }

    protected function durationKeys(): array
    {
        return ['callDetails.billsec', 'callDetails.duration'];
    }

    protected function recordingKeys(): array
    {
        return ['callDetails.linkToCallRecordInMyBusiness', 'callDetails.linkToCallRecordOverlayInMyBusiness', 'callDetails.recordingLink'];
    }

    protected function startKeys(): array
    {
        return ['callDetails.startTime'];
    }

    protected function statusKeys(): array
    {
        return ['callDetails.disposition'];
    }
}
