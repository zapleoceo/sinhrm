<?php

declare(strict_types=1);

namespace App\Modules\Channels\Services;

use App\Modules\Channels\Contracts\ChannelAdapter;
use App\Modules\Channels\Enums\ChannelMode;
use App\Modules\Integrations\Contracts\IntegrationRepository;
use App\Modules\Integrations\DTO\IntegrationConfig;
use App\Modules\Integrations\Enums\LogLevel;
use App\Modules\Integrations\Services\IntegrationConfigLoader;
use App\Modules\Integrations\Support\IntegrationRegistry;

/**
 * Bridge to the Integrations module for a channel: its mode (from the integration status), its runtime config
 * (settings + decrypted secrets, only in memory) and the integration event log ("last events" on the admin page).
 */
final readonly class ChannelContext
{
    public function __construct(
        private IntegrationRegistry $definitions,
        private IntegrationConfigLoader $loader,
        private IntegrationRepository $integrations,
    ) {}

    public function mode(ChannelAdapter $adapter): ChannelMode
    {
        return ChannelMode::of($this->loader->status($adapter->key()));
    }

    public function config(ChannelAdapter $adapter): IntegrationConfig
    {
        return $this->loader->load($this->definitions->get($adapter->key()));
    }

    /** @param  array<string, int|string|bool|null>  $context  codes and counters only — never payloads or secrets */
    public function log(ChannelAdapter $adapter, LogLevel $level, string $message, array $context = []): void
    {
        $this->integrations->log($this->integrations->findOrCreate($adapter->key()), $level, $message, $context);
    }
}
