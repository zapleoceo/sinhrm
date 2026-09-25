<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Definitions;

use App\Modules\Integrations\DTO\FieldSpec;
use App\Modules\Integrations\Enums\IntegrationGroup;

/** OpenRouter. No connection check: AI providers must not be called until the owner approves (AI policy). */
final class OpenRouterDefinition extends AbstractDefinition
{
    public function key(): string
    {
        return 'openrouter';
    }

    public function group(): IntegrationGroup
    {
        return IntegrationGroup::Ai;
    }

    public function fields(): array
    {
        return [
            FieldSpec::secret('api_key'),
        ];
    }
}
