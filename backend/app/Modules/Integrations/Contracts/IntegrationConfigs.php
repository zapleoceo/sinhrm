<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Contracts;

use App\Modules\Integrations\DTO\IntegrationConfig;
use App\Modules\Integrations\Enums\IntegrationStatus;

/**
 * Runtime config of an integration for the modules that talk to the service (Ai, Channels): non-secret settings with
 * defaults + decrypted secrets, and the current status. Implemented by Integrations\Services\IntegrationConfigLoader.
 */
interface IntegrationConfigs
{
    public function load(IntegrationDefinition $definition): IntegrationConfig;

    /** Current status; an integration without a row is "off". */
    public function status(string $key): IntegrationStatus;
}
