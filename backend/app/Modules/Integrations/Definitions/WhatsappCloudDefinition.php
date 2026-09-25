<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Definitions;

use App\Modules\Integrations\DTO\FieldSpec;
use App\Modules\Integrations\Enums\IntegrationGroup;

/** WhatsApp Cloud API (Meta). No connection check yet. */
final class WhatsappCloudDefinition extends AbstractDefinition
{
    public function key(): string
    {
        return 'whatsapp_cloud';
    }

    public function group(): IntegrationGroup
    {
        return IntegrationGroup::Messengers;
    }

    public function fields(): array
    {
        return [
            FieldSpec::text('phone_number_id', required: true),
            FieldSpec::text('waba_id', required: true),
            FieldSpec::secret('access_token'),
        ];
    }
}
