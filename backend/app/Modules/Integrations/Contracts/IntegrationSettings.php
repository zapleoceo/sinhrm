<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Contracts;

use App\Models\User;
use App\Modules\Integrations\DTO\IntegrationView;

/**
 * Changing an integration's settings from another module (the AI prompt editor switches the broker capability):
 * same validation, audit (integration_logs) and secret handling as the integrations screen. Implemented by
 * Integrations\Services\IntegrationService.
 */
interface IntegrationSettings
{
    /**
     * @param  array<string, mixed>  $settings  non-secret fields (already validated against the FieldSpec)
     * @param  array<string, string|null>  $secrets  value = set, null = delete, "" or absent = unchanged
     */
    public function update(User $actor, IntegrationDefinition $definition, array $settings, array $secrets): IntegrationView;
}
