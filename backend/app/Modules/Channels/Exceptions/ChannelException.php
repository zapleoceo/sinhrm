<?php

declare(strict_types=1);

namespace App\Modules\Channels\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Business error of the Channels module; rendered as {message, code} with its HTTP status. Never contains secrets. */
final class ChannelException extends RuntimeException
{
    private function __construct(public readonly string $errorCode, public readonly int $status)
    {
        parent::__construct($errorCode);
    }

    /** The channel is off, has no adapter or no credentials: the UI offers "log manually". */
    public static function notConnected(): self
    {
        return new self('channel_not_connected', 422);
    }

    /** No earlier conversation to reply into (Telegram chat / Viber user): the candidate has to write first. */
    public static function noConversation(): self
    {
        return new self('no_conversation', 422);
    }

    /** WhatsApp: outside the 24-hour customer service window only an approved template may be sent. */
    public static function templateRequired(): self
    {
        return new self('template_required', 422);
    }

    public static function invalidRecipient(): self
    {
        return new self('invalid_recipient', 422);
    }

    /** The provider refused or was unreachable (details only as a code in the integration log). */
    public static function sendFailed(): self
    {
        return new self('send_failed', 502);
    }

    public static function telephonyNotConnected(): self
    {
        return new self('telephony_not_connected', 422);
    }

    public static function clickToCallUnsupported(): self
    {
        return new self('click_to_call_unsupported', 422);
    }

    public static function noPhone(): self
    {
        return new self('no_phone', 422);
    }

    /** Simulation is allowed only while the integration is in demo mode. */
    public static function notDemo(): self
    {
        return new self('not_demo', 422);
    }

    /** Registration / test need the integration switched on (demo or connected). */
    public static function channelOff(): self
    {
        return new self('channel_off', 422);
    }

    public static function unsupported(): self
    {
        return new self('unsupported', 422);
    }

    public function render(): JsonResponse
    {
        return new JsonResponse(['message' => $this->errorCode, 'code' => $this->errorCode], $this->status);
    }
}
