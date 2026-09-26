<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\Services;

use App\Modules\GoogleWorkspace\Contracts\Mailer;
use App\Modules\GoogleWorkspace\DTO\OutgoingMail;
use App\Modules\GoogleWorkspace\DTO\SentMail;
use App\Modules\GoogleWorkspace\Enums\GoogleService;
use App\Modules\GoogleWorkspace\Enums\MailerState;
use App\Modules\GoogleWorkspace\Exceptions\GoogleException;
use App\Modules\GoogleWorkspace\Support\GoogleApi;
use App\Modules\GoogleWorkspace\Support\MimeMessage;
use App\Modules\Integrations\Enums\LogLevel;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Str;

/**
 * Mailer over Gmail REST: POST https://gmail.googleapis.com/gmail/v1/users/me/messages/send {raw, threadId?}.
 * Safety: the host is a constant (never user input), redirects off, short timeouts, the token comes from the
 * SecretVault through GoogleApi and is never logged (GoogleApi logs nothing, the integration log gets codes only).
 * Rate limit: MAX_PER_HOUR sends per mailbox per hour (on top of the per-user route throttle), so a loop or a workflow
 * burst cannot burn the Gmail sending quota or get the mailbox flagged as spam.
 */
final readonly class GmailMailer implements Mailer
{
    public const int MAX_PER_HOUR = 60;

    public const string LIMITER_KEY = 'google:gmail-send';

    public function __construct(
        private GoogleApi $api,
        private GoogleConnectionStore $connections,
        private RateLimiter $limiter,
    ) {}

    public function state(): MailerState
    {
        $state = $this->connections->state(GoogleService::Gmail);
        if (! $state->usable) {
            return MailerState::NotConnected;
        }

        return $state->canSend() ? MailerState::Ready : MailerState::ReconnectToSend;
    }

    public function send(OutgoingMail $mail): SentMail
    {
        match ($this->state()) {
            MailerState::NotConnected => throw GoogleException::notConnected(GoogleService::Gmail->value),
            MailerState::ReconnectToSend => throw GoogleException::sendScopeMissing(),
            MailerState::Ready => null,
        };
        $raw = MimeMessage::build($mail, Str::random(32));
        // Atomic: hit() is a cache increment that returns the new count, so two parallel sends can never both pass
        // the last free slot (a check-then-hit would let them).
        if ($this->limiter->hit(self::LIMITER_KEY, 3600) > self::MAX_PER_HOUR) {
            $this->connections->log(GoogleService::Gmail, LogLevel::Warning, 'gmail_send_rate_limited');

            throw GoogleException::sendRateLimited();
        }

        $body = ['raw' => MimeMessage::base64Url($raw)];
        $threadId = $mail->threadId !== null && self::isId($mail->threadId) ? $mail->threadId : null;
        if ($threadId !== null) {
            $body['threadId'] = $threadId;
        }
        try {
            $json = $this->api->post(GoogleService::Gmail, GoogleGmailClient::BASE.'/messages/send', [], $body);
        } catch (GoogleException $e) {
            $this->connections->log(GoogleService::Gmail, LogLevel::Error, 'gmail_send_failed', ['code' => $e->errorCode]);

            throw $e;
        }
        $id = $json['id'] ?? null;
        if (! is_string($id) || ! self::isId($id)) {
            throw GoogleException::badResponse();
        }
        $thread = $json['threadId'] ?? null;

        return new SentMail($id, is_string($thread) && self::isId($thread) ? $thread : $threadId);
    }

    private static function isId(string $id): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) === 1;
    }
}
