<?php

declare(strict_types=1);

namespace App\Modules\Auth\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Blocked = 'blocked';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
