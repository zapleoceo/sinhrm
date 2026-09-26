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
use App\Modules\GoogleWorkspace\Contracts\Mailer;
use App\Modules\GoogleWorkspace\DTO\OutgoingMail;
use App\Modules\GoogleWorkspace\Enums\GoogleService;
use App\Modules\GoogleWorkspace\Enums\MailerState;
use App\Modules\GoogleWorkspace\Exceptions\GoogleException;
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
 * E-mail goes through the Mailer (connected Gmail with gmail.send): to the candidate's e-mail, as a reply in the
 * Gmail thread of the candidate's last mail when that mail came from the candidate's own address.
 */
final readonly class MessageService
{
    public function __construct(
        private ChannelRegistry $channels,
        private ChannelContext $context,
        private TouchpointRepository $touchpoints,
        private ApplicationRepository $applications,
        private TouchpointIngestor $ingestor,
        private Mailer $mailer,
    ) {}

    /** @throws ChannelException|RecruitingException */
    public function send(User $actor, Candidate $candidate, Channel $channel, string $text, ?int $applicationId, ?string $subject = null): Touchpoint
    {
        if ($channel === Channel::Email) {
            return $this->sendEmail($actor, $candidate, $text, $applicationId, $subject);
        }
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

    /** @throws ChannelException|RecruitingException */
    private function sendEmail(User $actor, Candidate $candidate, string $text, ?int $applicationId, ?string $subject): Touchpoint
    {
        if ($this->mailer->state() !== MailerState::Ready) {
            throw ChannelException::notConnected();
        }
        if ($candidate->email === null || $candidate->email === '') {
            throw ChannelException::invalidRecipient();
        }
        if ($applicationId !== null && $this->applications->find($applicationId)?->candidate_id !== $candidate->id) {
            throw RecruitingException::applicationMismatch();
        }

        $meta = $this->touchpoints->latestInbound($candidate->id, Channel::Email)->meta ?? [];
        $fromCandidate = is_string($meta['from'] ?? null) && strcasecmp($meta['from'], $candidate->email) === 0;
        $threadId = $fromCandidate && is_string($meta['gmail_thread'] ?? null) ? $meta['gmail_thread'] : null;
        $inReplyTo = $fromCandidate && is_string($meta['message_id'] ?? null) ? $meta['message_id'] : null;
        $subject = trim((string) $subject);
        if ($subject === '') {
            $original = $fromCandidate && is_string($meta['subject'] ?? null) ? trim($meta['subject']) : '';
            $subject = $original === '' ? (string) config('app.name', 'SinHRM')
                : (preg_match('/^re:/i', $original) === 1 ? $original : 'Re: '.$original);
        }
        $subject = mb_substr($subject, 0, 255);

        try {
            $sent = $this->mailer->send(new OutgoingMail($candidate->email, $subject, $text, $candidate->full_name, $threadId, $inReplyTo));
        } catch (GoogleException $e) {
            throw match ($e->errorCode) {
                'gmail_send_rate_limited' => ChannelException::rateLimited(),
                'invalid_mail' => ChannelException::invalidRecipient(),
                'reconnect_required', 'gmail_send_scope_missing', 'google_gmail_not_connected' => ChannelException::notConnected(),
                default => ChannelException::sendFailed(),
            };
        }

        $touchpoint = $this->ingestor->ingest(new IncomingMessage(
            channel: Channel::Email,
            direction: Direction::Out,
            occurredAt: Carbon::now(),
            contact: null,
            body: trim($subject."\n\n".$text),
            externalId: $sent->id,
            integrationKey: GoogleService::Gmail->integrationKey(),
            authorId: $actor->id,
            viaProduct: true,
            meta: array_filter(['subject' => $subject, 'gmail_thread' => $sent->threadId], 'is_string'),
            candidateId: $candidate->id,
            applicationId: $applicationId,
        ));

        return $touchpoint->load('author');
    }
}
