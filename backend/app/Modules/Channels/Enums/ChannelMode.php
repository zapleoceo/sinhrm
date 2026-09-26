<?php

declare(strict_types=1);

namespace App\Modules\Channels\Enums;

use App\Modules\Integrations\Enums\IntegrationStatus;

/**
 * What the product does with a channel, derived from the integration status:
 * off → nothing (webhooks 404, sending → channel_not_connected); demo → webhooks accepted, sending is recorded without
 * calling the provider, simulation allowed; live (connected | error) → real provider calls.
 */
enum ChannelMode: string
{
    case Off = 'off';
    case Demo = 'demo';
    case Live = 'live';

    public static function of(IntegrationStatus $status): self
    {
        return match ($status) {
            IntegrationStatus::Off => self::Off,
            IntegrationStatus::Demo => self::Demo,
            IntegrationStatus::Connected, IntegrationStatus::Error => self::Live,
        };
    }
}
