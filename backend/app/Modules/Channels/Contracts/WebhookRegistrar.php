<?php

declare(strict_types=1);

namespace App\Modules\Channels\Contracts;

use App\Modules\Channels\Exceptions\ChannelException;
use App\Modules\Integrations\DTO\IntegrationConfig;

/** A provider whose webhook is registered through its API ("Register webhook" button). */
interface WebhookRegistrar
{
    /**
     * Name of the vault secret that the registration generates (sent to the provider, then checked on every
     * webhook), or null when the provider signs with an existing secret.
     */
    public function generatedSecret(): ?string;

    /** @throws ChannelException send_failed */
    public function register(string $url, ?string $secret, IntegrationConfig $config): void;
}
