<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Definitions;

use App\Modules\Integrations\Enums\IntegrationGroup;

/** Google Sheets. Connected by the OAuth consent flow (GoogleWorkspace module); the import is configured on its own page. */
final class GoogleSheetsDefinition extends AbstractDefinition
{
    public function key(): string
    {
        return 'google_sheets';
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
