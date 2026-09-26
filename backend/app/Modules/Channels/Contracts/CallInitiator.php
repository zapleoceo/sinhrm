<?php

declare(strict_types=1);

namespace App\Modules\Channels\Contracts;

use App\Modules\Channels\Exceptions\ChannelException;
use App\Modules\Integrations\DTO\IntegrationConfig;

/** Telephony that can start a call from the card (click-to-call: the recruiter's line rings, then the candidate). */
interface CallInitiator
{
    /** @throws ChannelException telephony_not_connected | send_failed */
    public function call(string $phone, IntegrationConfig $config): void;
}
