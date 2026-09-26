<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Services;

use App\Modules\Integrations\Contracts\IntegrationDefinition;
use App\Modules\Integrations\Contracts\IntegrationRepository;
use App\Modules\Integrations\Contracts\SecretVault;
use App\Modules\Integrations\DTO\IntegrationConfig;
use App\Modules\Integrations\Enums\IntegrationStatus;

/**
 * Builds the runtime config of an integration: non-secret settings (defaults applied) + decrypted secrets from the
 * vault. Shared by connection checks and by modules that talk to the service (Channels webhooks and sending).
 */
final readonly class IntegrationConfigLoader
{
    public function __construct(
        private IntegrationRepository $integrations,
        private SecretVault $vault,
    ) {}

    public function load(IntegrationDefinition $definition): IntegrationConfig
    {
        $stored = $this->integrations->find($definition->key())->settings ?? [];
        $settings = [];
        $secrets = [];
        foreach ($definition->fields() as $field) {
            if ($field->isSecret()) {
                $value = $this->vault->get($definition->key(), $field->name);
                if ($value !== null && $value !== '') {
                    $secrets[$field->name] = $value;
                }
            } else {
                $settings[$field->name] = $stored[$field->name] ?? $field->default;
            }
        }

        return new IntegrationConfig($definition->key(), $settings, $secrets);
    }

    /** Current status; an integration without a row is "off". */
    public function status(string $key): IntegrationStatus
    {
        return $this->integrations->find($key)->status ?? IntegrationStatus::Off;
    }
}
