<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Definitions;

use App\Modules\Integrations\DTO\FieldSpec;
use App\Modules\Integrations\Enums\IntegrationGroup;

/** Meta Lead Ads (Facebook/Instagram lead forms). No connection check yet. */
final class MetaLeadAdsDefinition extends AbstractDefinition
{
    public function key(): string
    {
        return 'meta_lead_ads';
    }

    public function group(): IntegrationGroup
    {
        return IntegrationGroup::Sources;
    }

    public function fields(): array
    {
        return [
            FieldSpec::text('page_id', required: true),
            FieldSpec::secret('access_token'),
        ];
    }
}
