<?php

declare(strict_types=1);

namespace App\Modules\Channels\DTO;

use App\Modules\Channels\Enums\ChannelMode;
use App\Modules\Channels\Enums\WebhookAuth;

/** What the Integrations page shows about a channel: where to paste the webhook and which actions exist. */
final readonly class ChannelInfo
{
    public function __construct(
        public string $key,
        public string $channel,
        public ChannelMode $mode,
        public string $webhookUrl,
        public WebhookAuth $auth,
        public bool $canRegister,
        public bool $canSend,
        public bool $canCall,
        public bool $handshake,
    ) {}
}
