<?php

declare(strict_types=1);

namespace App\Modules\Auth\Enums;

/**
 * Global (system) roles — Spatie role names, guard "web". Created by the Auth module data migrations.
 * Contextual roles are NOT here: the hiring manager is stored on the vacancy, the interviewer on the application
 * (Recruiting), and the line manager is computed from employees.manager_id (People). See docs/modules/auth.md.
 */
enum UserRole: string
{
    case Superadmin = 'superadmin';
    case Admin = 'admin';
    case HrManager = 'hr_manager';
    case Recruiter = 'recruiter';
    case Employee = 'employee';
    case Viewer = 'viewer';

    /**
     * Roles a superadmin may give through an invitation (superadmin itself is bootstrapped or promoted).
     *
     * @return list<string>
     */
    public static function invitableValues(): array
    {
        return self::valuesOf(array_values(array_filter(self::cases(), static fn (self $r): bool => $r !== self::Superadmin)));
    }

    /** @return list<string> */
    public static function values(): array
    {
        return self::valuesOf(self::cases());
    }

    /**
     * People who act as HR: see every branch, manage employees, leave, cases and workflows.
     *
     * @return list<self>
     */
    public static function hrStaff(): array
    {
        return [self::Superadmin, self::Admin, self::HrManager];
    }

    /**
     * Create and edit vacancies, candidates and applications (within their branch scope).
     *
     * @return list<self>
     */
    public static function recruitingWriters(): array
    {
        return [self::Superadmin, self::Admin, self::Recruiter];
    }

    /**
     * See Recruiting by branch scope. Anyone else (employee) sees only what a contextual role opens to them.
     *
     * @return list<self>
     */
    public static function recruitingReaders(): array
    {
        return [...self::hrStaff(), self::Recruiter, self::Viewer];
    }

    /**
     * @param  list<self>  $roles
     * @return list<string>
     */
    public static function valuesOf(array $roles): array
    {
        return array_map(static fn (self $r): string => $r->value, $roles);
    }
}
