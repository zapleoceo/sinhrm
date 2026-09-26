<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Definitions;

use App\Modules\Integrations\DTO\FieldSpec;
use App\Modules\Integrations\Enums\IntegrationGroup;

/**
 * PLACEHOLDER: qualified e-signature (КЕП) of employee documents through Дія.Підпис or Вчасно. Nothing calls a
 * provider yet — the Documents module only has the "Ознайомлений" acknowledgement (manual_ack); the signature method
 * kep_pending is reserved. The card exists so the settings have a home; it stays "off" and has no connection check.
 * Needs before implementation: provider choice, contract/API access, legal review of the signing flow.
 */
final class KepSigningDefinition extends AbstractDefinition
{
    public function key(): string
    {
        return 'kep_signing';
    }

    public function group(): IntegrationGroup
    {
        return IntegrationGroup::Documents;
    }

    public function fields(): array
    {
        return [
            FieldSpec::select('provider', ['diia_signature', 'vchasno'], default: 'diia_signature'),
            FieldSpec::secret('api_token', required: false),
        ];
    }
}
