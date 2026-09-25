<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Definitions;

use App\Modules\Integrations\DTO\FieldSpec;
use App\Modules\Integrations\Enums\IntegrationGroup;

/** Wazzup (messenger aggregator). No connection check yet. */
final class WazzupDefinition extends AbstractDefinition
{
    public function key(): string
    {
        return 'wazzup';
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
