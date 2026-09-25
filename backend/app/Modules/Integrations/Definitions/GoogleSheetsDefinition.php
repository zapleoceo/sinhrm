<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Definitions;

use App\Modules\Integrations\DTO\FieldSpec;
use App\Modules\Integrations\Enums\IntegrationGroup;

/** Google Sheets. Tokens come later from the OAuth consent flow. */
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
        return [
            FieldSpec::url('sheet_url'),
        ];
    }
}
