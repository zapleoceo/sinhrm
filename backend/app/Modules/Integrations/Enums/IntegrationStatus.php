<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Enums;

enum IntegrationStatus: string
{
    case Off = 'off';
    case Demo = 'demo';
    case Connected = 'connected';
    case Error = 'error';

    /**
     * Statuses a superadmin may set by hand; connected/error come only from a connection check.
     *
     * @return list<string>
     */
    public static function manualValues(): array
    {
        return [self::Off->value, self::Demo->value];
    }
}
