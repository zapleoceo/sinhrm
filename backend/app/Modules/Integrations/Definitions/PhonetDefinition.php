<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Definitions;

use App\Modules\Integrations\DTO\FieldSpec;
use App\Modules\Integrations\Enums\IntegrationGroup;

/** Phonet telephony. No connection check yet. */
final class PhonetDefinition extends AbstractDefinition
{
    public function key(): string
    {
        return 'phonet';
    }

    public function group(): IntegrationGroup
    {
        return IntegrationGroup::Telephony;
    }

    public function fields(): array
    {
        return [
            FieldSpec::text('domain', required: true),
            FieldSpec::secret('api_key'),
            // Shared secret of the webhook URL (?token=…), compared in constant time. Provisional: the provider's
            // own signature scheme is not confirmed on a real account.
            FieldSpec::secret('webhook_token', required: false),
        ];
    }
}
