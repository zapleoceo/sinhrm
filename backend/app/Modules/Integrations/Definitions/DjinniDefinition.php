<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Definitions;

use App\Modules\Integrations\DTO\FieldSpec;
use App\Modules\Integrations\Enums\IntegrationGroup;

/** Djinni: responses inbox or API token. No connection check yet. */
final class DjinniDefinition extends AbstractDefinition
{
    public function key(): string
    {
        return 'djinni';
    }

    public function group(): IntegrationGroup
    {
        return IntegrationGroup::Sources;
    }

    public function fields(): array
    {
        return [
            FieldSpec::text('inbox'),
            FieldSpec::secret('api_token', required: false),
        ];
    }
}
