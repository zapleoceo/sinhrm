<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Enums;

enum LogLevel: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
}
