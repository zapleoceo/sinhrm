<?php

declare(strict_types=1);

namespace App\Modules\Auth\Enums;

/** Spatie role names (guard "web"). Created by the Auth module data migration. */
enum UserRole: string
{
    case Superadmin = 'superadmin';
    case Admin = 'admin';
    case Recruiter = 'recruiter';
    case Viewer = 'viewer';

    /**
     * Roles a superadmin may give through an invitation (superadmin itself is bootstrapped or promoted).
     *
     * @return list<string>
     */
    public static function invitableValues(): array
    {
        return [self::Admin->value, self::Recruiter->value, self::Viewer->value];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $r): string => $r->value, self::cases());
    }
}
