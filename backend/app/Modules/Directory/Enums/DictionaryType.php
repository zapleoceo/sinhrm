<?php

declare(strict_types=1);

namespace App\Modules\Directory\Enums;

use App\Modules\Directory\Models\Branch;
use App\Modules\Directory\Models\City;
use App\Modules\Directory\Models\Department;
use App\Modules\Directory\Models\DictionaryItem;
use App\Modules\Directory\Models\Position;

/** The company dictionaries, as they appear in the URL (/api/directory/{type}). */
enum DictionaryType: string
{
    case Branches = 'branches';
    case Cities = 'cities';
    case Departments = 'departments';
    case Positions = 'positions';

    /** @return class-string<DictionaryItem> */
    public function modelClass(): string
    {
        return match ($this) {
            self::Branches => Branch::class,
            self::Cities => City::class,
            self::Departments => Department::class,
            self::Positions => Position::class,
        };
    }

    /**
     * Sintegrum REST resource ("<base>/<resource>/list"). Positions are "jobs" in Sintegrum.
     * Paths come from the Sintegrum apidoc (list actions); the live response is NOT verified yet.
     */
    public function sintegrumResource(): string
    {
        return match ($this) {
            self::Branches => 'branches',
            self::Cities => 'cities',
            self::Departments => 'departments',
            self::Positions => 'jobs',
        };
    }

    /**
     * Import order: cities before branches (a branch may reference its city).
     *
     * @return list<self>
     */
    public static function importOrder(): array
    {
        return [self::Cities, self::Branches, self::Departments, self::Positions];
    }
}
