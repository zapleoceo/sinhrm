<?php

declare(strict_types=1);

namespace App\Modules\Channels\Contracts;

use App\Modules\Channels\DTO\DemoSeed;
use App\Modules\Channels\DTO\IncomingEvent;
use App\Modules\Channels\Enums\WebhookAuth;
use App\Modules\Integrations\DTO\IntegrationConfig;
use App\Modules\Recruiting\Enums\Channel;
use Illuminate\Http\Request;

/**
 * One external channel (messenger or telephony) that delivers events to POST /api/webhooks/{key}.
 * To add a channel: a class implementing this interface (plus MessageSender / WebhookRegistrar / HandshakeResponder /
 * CallInitiator when the provider supports it) and one line in ChannelsServiceProvider::ADAPTERS.
 * Adapters never log payloads or secrets; they only map provider JSON to IncomingEvent.
 */
interface ChannelAdapter
{
    /** Integration key in the Integrations registry (also the webhook URL segment), e.g. "telegram_business". */
    public function key(): string;

    /** Timeline channel of the touchpoints this adapter creates. */
    public function channel(): Channel;

    /** How the webhook proves its origin (shown to the owner on the Integrations page). */
    public function auth(): WebhookAuth;

    /** Signature / secret check of a webhook request. Must be constant-time; false when the secret is not set. */
    public function verify(Request $request, IntegrationConfig $config): bool;

    /**
     * Provider payload → events. Tolerant: unknown shapes give an empty list, never an exception.
     *
     * @param  array<array-key, mixed>  $payload
     * @return list<IncomingEvent>
     */
    public function parse(array $payload, IntegrationConfig $config): array;

    /**
     * A realistic synthetic payload in the provider's format (demo mode): it goes through the same parse → ingest path.
     *
     * @return array<string, mixed>
     */
    public function demoPayload(DemoSeed $seed, IntegrationConfig $config): array;

    /** @return array<string, mixed> body of the 200 answer the provider expects */
    public function acknowledge(): array;
}
