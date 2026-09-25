<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Definitions;

use App\Modules\Integrations\Enums\IntegrationGroup;

/** Google Calendar. Tokens come later from the OAuth consent flow. */
final class GoogleCalendarDefinition extends AbstractDefinition
{
    public function key(): string
    {
        return 'google_calendar';
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
