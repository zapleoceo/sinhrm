<?php

declare(strict_types=1);

namespace App\Modules\People\Enums;

/** Whitelist of employee fields an employee may ask to change through self-service. */
enum ChangeableField: string
{
    case Phone = 'phone';
    case PersonalEmail = 'personal_email';
    case Address = 'address';
    case EmergencyContact = 'emergency_contact';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $f): string => $f->value, self::cases());
    }
}
