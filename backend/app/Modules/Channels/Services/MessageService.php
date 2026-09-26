<?php

declare(strict_types=1);

namespace App\Modules\Channels\Services;

use App\Models\User;
use App\Modules\Channels\Contracts\ChannelAdapter;
use App\Modules\Channels\Contracts\MessageSender;
use App\Modules\Channels\DTO\OutgoingMessage;
use App\Modules\Channels\DTO\Recipient;
use App\Modules\Channels\DTO\SentMessage;
use App\Modules\Channels\Enums\ChannelMode;
use App\Modules\Channels\Exceptions\ChannelException;
use App\Modules\Channels\Support\ChannelRegistry;
use App\Modules\Integrations\Enums\LogLevel;
use App\Modules\Recruiting\Contracts\ApplicationRepository;
use App\Modules\Recruiting\Contracts\TouchpointIngestor;
use App\Modules\Recruiting\Contracts\TouchpointRepository;
use App\Modules\Recruiting\DTO\IncomingMessage;
use App\Modules\Recruiting\Enums\Channel;
use App\Modules\Recruiting\Enums\Direction;
use App\Modules\Recruiting\Exceptions\RecruitingException;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\Touchpoint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Sends a message from the candidate card through the channel's adapter and records it as an outbound touchpoint
 * (via_product = true) through the same TouchpointIngestor as inbound events. Demo mode records the message without
 * calling the provider (meta.demo). Channel off / no adapter / no credentials → channel_not_connected (the UI then
 * offers to log the touch manually).
 */
final readonly class MessageService
{
    public function __construct(
        private ChannelRegistry $channels,
        private ChannelContext $context,
        private TouchpointRepository $touchpoints,
        private ApplicationRepository $applications,
        private TouchpointIngestor $ingestor,
    ) {}

    /** @throws ChannelException|RecruitingException */
    public function send(User $actor, Candidate $candidate, Channel $channel, string $text, ?int $applicationId): Touchpoint
    {
        $adapter = $this->channels->senderFor($channel);
        $mode = $adapter === null ? ChannelMode::Off : $this->context->mode($adapter);
        if ($adapter === null || ! $adapter instanceof MessageSender || $mode === ChannelMode::Off) {
            throw ChannelException::notConnected();
        }
        if ($applicationId !== null && $this->applications->find($applicationId)?->candidate_id !== $candidate->id) {
            throw RecruitingException::applicationMismatch();
        }

        $now = Carbon::now();
        $thread = $this->touchpoints->latestThreadOf($candidate->id, $channel)?->meta['thread'] ?? null;
        $recipient = new Recipient(
            $candidate->phone,
            $candidate->telegram_username,
            is_string($thread) ? $thread : null,
            $this->touchpoints->lastInboundAt($candidate->id, $channel),
        );
        $sent = $mode === ChannelMode::Demo
            ? new SentMessage('demo:'.Str::uuid()->toString(), $recipient->thread)
            : $this->deliver($adapter, new OutgoingMessage($recipient, $text, $now));

        $touchpoint = $this->ingestor->ingest(new IncomingMessage(
            channel: $channel,
            direction: Direction::Out,
            occurredAt: $now,
            contact: null,
            body: $text,
            externalId: $sent->externalId,
            integrationKey: $adapter->key(),
            authorId: $actor->id,
            viaProduct: true,
            meta: $mode === ChannelMode::Demo ? ['demo' => true] : [],
            thread: $sent->thread,
            candidateId: $candidate->id,
            applicationId: $applicationId,
        ));

        return $touchpoint->load('author');
    }

    private function deliver(ChannelAdapter&MessageSender $adapter, OutgoingMessage $message): SentMessage
    {
        try {
            return $adapter->send($message, $this->context->config($adapter));
        } catch (ChannelException $e) {
            $this->context->log($adapter, LogLevel::Error, 'send_failed', ['code' => $e->errorCode]);

            throw $e;
        }
    }
}
