<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Definitions;

use App\Modules\Integrations\DTO\FieldSpec;
use App\Modules\Integrations\Enums\IntegrationGroup;

/** Ringostat call tracking. No connection check yet. */
final class RingostatDefinition extends AbstractDefinition
{
    public function key(): string
    {
        return 'ringostat';
    }

    public function group(): IntegrationGroup
    {
        return IntegrationGroup::Telephony;
    }

    public function fields(): array
    {
        return [
            FieldSpec::text('project_id', required: true),
            FieldSpec::secret('api_key'),
            // Click-to-call (provisional): SIP extension that rings first.
            FieldSpec::text('callback_extension'),
            // Shared secret of the webhook URL (?token=…), compared in constant time. Provisional: the provider's
            // own signature scheme is not confirmed on a real account.
            FieldSpec::secret('webhook_token', required: false),
        ];
    }
}
