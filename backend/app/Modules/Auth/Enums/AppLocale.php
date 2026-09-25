<?php

declare(strict_types=1);

namespace App\Modules\Auth\Enums;

/** UI languages supported by the SPA (uk is the default). */
enum AppLocale: string
{
    case Uk = 'uk';
    case Ru = 'ru';
    case En = 'en';
}
