<?php

declare(strict_types=1);

namespace App\Modules\Channels\Services;

use App\Models\User;
use App\Modules\Channels\Contracts\CallInitiator;
use App\Modules\Channels\Contracts\ChannelAdapter;
use App\Modules\Channels\Contracts\HandshakeResponder;
use App\Modules\Channels\Contracts\MessageSender;
use App\Modules\Channels\Contracts\WebhookRegistrar;
use App\Modules\Channels\DTO\ChannelInfo;
use App\Modules\Channels\DTO\SentMessage;
use App\Modules\Channels\Enums\ChannelMode;
use App\Modules\Channels\Exceptions\ChannelException;
use App\Modules\Channels\Support\ChannelRegistry;
use App\Modules\Integrations\Contracts\SecretVault;
use App\Modules\Integrations\Enums\LogLevel;
use Illuminate\Support\Str;

/** Superadmin actions of the Integrations page for channels: webhook info, registration, test message. */
final readonly class ChannelAdminService
{
    private const int SECRET_LENGTH = 48;

    public function __construct(
        private ChannelRegistry $channels,
        private ChannelContext $context,
        private SecretVault $vault,
    ) {}

    /** @return list<ChannelInfo> */
    public function overview(): array
    {
        return array_map(fn (ChannelAdapter $a): ChannelInfo => new ChannelInfo(
            key: $a->key(),
            channel: $a->channel()->value,
            mode: $this->context->mode($a),
            webhookUrl: self::webhookUrl($a),
            auth: $a->auth(),
            canRegister: $a instanceof WebhookRegistrar,
            canSend: $a instanceof MessageSender,
            canCall: $a instanceof CallInitiator,
            handshake: $a instanceof HandshakeResponder,
        ), $this->channels->all());
    }

    /**
     * Registers the webhook URL at the provider. A generated secret (Telegram) is stored only after the provider
     * accepted it, so a failed registration keeps the previous working secret.
     */
    public function register(User $actor, ChannelAdapter $adapter): void
    {
        if (! $adapter instanceof WebhookRegistrar) {
            throw ChannelException::unsupported();
        }
        if ($this->context->mode($adapter) === ChannelMode::Off) {
            throw ChannelException::channelOff();
        }
        $name = $adapter->generatedSecret();
        $secret = $name === null ? null : Str::random(self::SECRET_LENGTH);
        try {
            $adapter->register(self::webhookUrl($adapter), $secret, $this->context->config($adapter));
        } catch (ChannelException $e) {
            $this->context->log($adapter, LogLevel::Error, 'webhook_register_failed', ['code' => $e->errorCode, 'user_id' => $actor->id]);

            throw $e;
        }
        if ($name !== null && $secret !== null) {
            $this->vault->put($adapter->key(), $name, $secret, $actor->id);
        }
        $this->context->log($adapter, LogLevel::Info, 'webhook_registered', ['user_id' => $actor->id]);
    }

    /** A real message to a recipient typed by the superadmin (verifies tokens end to end). Not a touchpoint. */
    public function test(User $actor, ChannelAdapter $adapter, string $to, string $text): SentMessage
    {
        if (! $adapter instanceof MessageSender) {
            throw ChannelException::unsupported();
        }
        if ($this->context->mode($adapter) === ChannelMode::Off) {
            throw ChannelException::channelOff();
        }
        try {
            $sent = $adapter->sendTest($to, $text, $this->context->config($adapter));
        } catch (ChannelException $e) {
            $this->context->log($adapter, LogLevel::Error, 'test_failed', ['code' => $e->errorCode, 'user_id' => $actor->id]);

            throw $e;
        }
        $this->context->log($adapter, LogLevel::Info, 'test_sent', ['user_id' => $actor->id]);

        return $sent;
    }

    /** Public URL to paste in the provider console (telephony adds ?token=<webhook_token> on the page). */
    public static function webhookUrl(ChannelAdapter $adapter): string
    {
        return route('channels.webhook', ['channelKey' => $adapter->key()]);
    }
}
