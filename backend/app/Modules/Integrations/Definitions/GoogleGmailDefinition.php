<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Definitions;

use App\Modules\Integrations\Enums\IntegrationGroup;

/** Gmail. Connected by the OAuth consent flow (GoogleWorkspace module): no editable fields, tokens live in the vault. */
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
        return [];
    }
}
