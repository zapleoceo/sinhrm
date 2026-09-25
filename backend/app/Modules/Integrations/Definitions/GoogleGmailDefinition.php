<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Definitions;

use App\Modules\Integrations\DTO\FieldSpec;
use App\Modules\Integrations\Enums\IntegrationGroup;

/** Gmail. Tokens come later from the OAuth consent flow, so there are no secrets here. */
final class GoogleGmailDefinition extends AbstractDefinition
{
    public function key(): string
    {
        return 'google_gmail';
    }

    public function group(): IntegrationGroup
    {
        return IntegrationGroup::Google;
    }

    public function fields(): array
    {
        return [
            FieldSpec::text('mailbox'),
        ];
    }
}
