<?php

declare(strict_types=1);

namespace App\Modules\Integrations\DTO;

use App\Modules\Integrations\Contracts\IntegrationDefinition;
use App\Modules\Integrations\Enums\IntegrationStatus;
use Illuminate\Support\Carbon;

/** Safe read model of one integration for the API: settings + masked secret metadata, no secret values. */
final readonly class IntegrationView
{
    /**
     * @param  array<string, mixed>  $settings  stored non-secret settings
     * @param  array<string, SecretMeta>  $secrets
     */
    public function __construct(
        public IntegrationDefinition $definition,
        public IntegrationStatus $status,
        public array $settings,
        public array $secrets,
        public ?Carbon $lastCheckedAt,
        public ?string $lastError,
        public ?Carbon $updatedAt,
    ) {}
}
