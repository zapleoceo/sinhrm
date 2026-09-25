<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Enums;

enum FieldType: string
{
    case Text = 'text';
    /** Stored encrypted in integration_secrets; never returned by the API. */
    case Secret = 'secret';
    case Url = 'url';
    case Select = 'select';
}
