<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Definitions;

use App\Modules\Integrations\Enums\IntegrationGroup;

/** Google Calendar. Connected by the OAuth consent flow (GoogleWorkspace module); used for meetings from the candidate card. */
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
