<?php

declare(strict_types=1);

namespace App\Modules\Channels\Services;

use App\Models\User;
use App\Modules\Channels\Contracts\ChannelAdapter;
use App\Modules\Channels\Contracts\HandshakeResponder;
use App\Modules\Channels\DTO\DemoSeed;
use App\Modules\Channels\DTO\IncomingEvent;
use App\Modules\Channels\DTO\WebhookResult;
use App\Modules\Channels\Enums\ChannelMode;
use App\Modules\Channels\Exceptions\ChannelException;
use App\Modules\Integrations\Enums\LogLevel;
use App\Modules\Recruiting\Contracts\TouchpointIngestor;
use App\Modules\Recruiting\DTO\IncomingMessage;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The ONE inbound path of every channel: verify → parse → TouchpointIngestor (match by thread / contact, else inbox;
 * idempotent by (channel, external id), so provider retries and replays never duplicate a touchpoint).
 * A switched-off integration answers 404; a bad signature 403. Payloads and secrets are never logged.
 */
final readonly class WebhookService
{
    public function __construct(
        private ChannelContext $context,
        private TouchpointIngestor $ingestor,
    ) {}

    /** GET verification (WhatsApp): the challenge to echo back. */
    public function handshake(ChannelAdapter $adapter, Request $request): string
    {
        if (! $adapter instanceof HandshakeResponder || $this->context->mode($adapter) === ChannelMode::Off) {
            throw new NotFoundHttpException;
        }

        return $adapter->handshake($request, $this->context->config($adapter)) ?? throw new AccessDeniedHttpException;
    }

    public function receive(ChannelAdapter $adapter, Request $request): WebhookResult
    {
        if ($this->context->mode($adapter) === ChannelMode::Off) {
            throw new NotFoundHttpException;
        }
        $config = $this->context->config($adapter);
        if (! $adapter->verify($request, $config)) {
            $this->context->log($adapter, LogLevel::Warning, 'webhook_rejected');

            throw new AccessDeniedHttpException;
        }
        $payload = $request->isJson() ? $request->json()->all() : $request->request->all();

        return $this->ingest($adapter, $adapter->parse($payload, $config), 'webhook_received');
    }

    /**
     * Demo mode: a synthetic provider payload through the same parse → ingest path (no signature: superadmin only,
     * and only while the integration is in demo).
     */
    public function simulate(User $actor, ChannelAdapter $adapter, DemoSeed $seed): WebhookResult
    {
        if ($this->context->mode($adapter) !== ChannelMode::Demo) {
            throw ChannelException::notDemo();
        }
        $config = $this->context->config($adapter);
        $events = array_map(
            static fn (IncomingEvent $e): IncomingEvent => $e->withMeta(['demo' => true]),
            $adapter->parse($adapter->demoPayload($seed, $config), $config),
        );

        return $this->ingest($adapter, $events, 'simulated', ['user_id' => $actor->id]);
    }

    /**
     * @param  list<IncomingEvent>  $events
     * @param  array<string, int>  $logContext
     */
    private function ingest(ChannelAdapter $adapter, array $events, string $logMessage, array $logContext = []): WebhookResult
    {
        $touchpoints = [];
        $created = 0;
        foreach ($events as $event) {
            if (! $event->kind->createsTouch()) {
                $this->logStatus($adapter, $event);

                continue;
            }
            $touchpoint = $this->ingestor->ingest(new IncomingMessage(
                channel: $adapter->channel(),
                direction: $event->direction,
                occurredAt: $event->occurredAt,
                contact: $event->contact,
                body: $event->body,
                externalId: $event->externalId,
                integrationKey: $adapter->key(),
                viaProduct: false,
                meta: $event->meta,
                thread: $event->thread,
            ));
            $touchpoints[] = $touchpoint;
            $created += $touchpoint->wasRecentlyCreated ? 1 : 0;
        }
        // Only deliveries that stored something new: retries and receipts do not flood the "last events" list.
        if ($created > 0) {
            $this->context->log($adapter, LogLevel::Info, $logMessage, $logContext + ['events' => count($events), 'created' => $created]);
        }

        return new WebhookResult(count($events), $touchpoints, $created);
    }

    private function logStatus(ChannelAdapter $adapter, IncomingEvent $event): void
    {
        $code = $event->meta['error_code'] ?? null;
        if (is_int($code)) {
            $this->context->log($adapter, LogLevel::Warning, 'delivery_failed', ['error_code' => $code]);
        }
        $status = $event->meta['status'] ?? null;
        if ($status === 'business_connected' || $status === 'business_disconnected') {
            $this->context->log($adapter, LogLevel::Info, $status);
        }
    }
}
