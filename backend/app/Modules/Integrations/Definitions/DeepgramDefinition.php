<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Definitions;

use App\Modules\Integrations\DTO\FieldSpec;
use App\Modules\Integrations\Enums\IntegrationGroup;

/** Deepgram speech-to-text. No connection check (would be a billable provider call). */
final class DeepgramDefinition extends AbstractDefinition
{
    public function key(): string
    {
        return 'deepgram';
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
