<?php

declare(strict_types=1);

namespace App\Modules\Directory\Enums;

/** Status of a dictionary item. Items are never deleted, only disabled. */
enum DirectoryStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}
