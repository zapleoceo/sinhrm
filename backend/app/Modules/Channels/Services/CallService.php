<?php

declare(strict_types=1);

namespace App\Modules\Channels\Services;

use App\Models\User;
use App\Modules\Channels\Contracts\CallInitiator;
use App\Modules\Channels\Enums\ChannelMode;
use App\Modules\Channels\Exceptions\ChannelException;
use App\Modules\Channels\Support\ChannelRegistry;
use App\Modules\Integrations\Enums\LogLevel;
use App\Modules\Recruiting\Models\Candidate;

/**
 * Click-to-call from the card. The first telephony integration in live mode (connected / error) is used; none →
 * telephony_not_connected; a provider without a callback API → click_to_call_unsupported. The call itself is
 * recorded later by the provider's call-end webhook (one ingestion path), not here.
 */
final readonly class CallService
{
    public function __construct(private ChannelRegistry $channels, private ChannelContext $context) {}

    /** @return string key of the telephony integration that places the call */
    public function start(User $actor, Candidate $candidate): string
    {
        foreach ($this->channels->telephony() as $adapter) {
            if ($this->context->mode($adapter) !== ChannelMode::Live) {
                continue;
            }
            if (! $adapter instanceof CallInitiator) {
                throw ChannelException::clickToCallUnsupported();
            }
            if ($candidate->phone === null) {
                throw ChannelException::noPhone();
            }
            try {
                $adapter->call($candidate->phone, $this->context->config($adapter));
            } catch (ChannelException $e) {
                $this->context->log($adapter, LogLevel::Error, 'call_failed', ['code' => $e->errorCode, 'user_id' => $actor->id]);

                throw $e;
            }
            $this->context->log($adapter, LogLevel::Info, 'call_requested', ['user_id' => $actor->id]);

            return $adapter->key();
        }

        throw ChannelException::telephonyNotConnected();
    }
}
