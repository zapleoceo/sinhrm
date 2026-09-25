<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Definitions;

use App\Modules\Integrations\DTO\FieldSpec;
use App\Modules\Integrations\Enums\IntegrationGroup;

/** Viber bot. No connection check yet. */
final class ViberDefinition extends AbstractDefinition
{
    public function key(): string
    {
        return 'viber';
    }

    public function group(): IntegrationGroup
    {
        return IntegrationGroup::Messengers;
    }

    public function fields(): array
    {
        return [
            FieldSpec::secret('token'),
        ];
    }
}
