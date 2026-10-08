<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Modules\Core\Support\UserTime;
use App\Modules\Documents\Enums\DocumentVariable;
use App\Modules\People\Models\Employee;
use Illuminate\Support\Carbon;

/**
 * Values of the template variables for one employee (dates as dd.mm.yyyy). Synthetic sample for the preview.
 * {Сьогодні} is the user's (Kyiv) date of the moment $today — at 00:30 Kyiv the UTC date is still yesterday.
 */
final class DocumentVariables
{
    /** @return array<string, string|null> DocumentVariable value → text */
    public static function forEmployee(Employee $employee, Carbon $today): array
    {
        $employee->loadMissing(['position', 'department', 'branch', 'manager']);
        $first = preg_split('/\s+/u', trim($employee->full_name)) ?: [];

        return [
            DocumentVariable::FullName->value => $employee->full_name,
            DocumentVariable::FirstName->value => $first[0] ?? null,
            DocumentVariable::Position->value => $employee->position?->name,
            DocumentVariable::Department->value => $employee->department?->name,
            DocumentVariable::Branch->value => $employee->branch?->name,
            DocumentVariable::HiredAt->value => $employee->hired_at->format('d.m.Y'),
            DocumentVariable::FiredAt->value => $employee->fired_at?->format('d.m.Y'),
            DocumentVariable::Manager->value => $employee->manager?->full_name,
            DocumentVariable::Today->value => self::today($today),
        ];
    }

    /** @return array<string, string> fictional values for the editor preview (public repository: synthetic only) */
    public static function sample(Carbon $today): array
    {
        $today = UserTime::now($today); // preview dates are the user's (Kyiv) calendar, like {Сьогодні}

        return [
            DocumentVariable::FullName->value => 'Олена Приклад',
            DocumentVariable::FirstName->value => 'Олена',
            DocumentVariable::Position->value => 'Адміністратор',
            DocumentVariable::Department->value => 'Навчальний відділ',
            DocumentVariable::Branch->value => 'Філія «Центр»',
            DocumentVariable::HiredAt->value => $today->copy()->subMonth()->format('d.m.Y'),
            DocumentVariable::FiredAt->value => $today->copy()->addYear()->format('d.m.Y'),
            DocumentVariable::Manager->value => 'Ірина Керівник',
            DocumentVariable::Today->value => self::today($today),
            DocumentVariable::Salary->value => '25 000 грн',
            DocumentVariable::StartDate->value => $today->copy()->addWeeks(2)->format('d.m.Y'),
            DocumentVariable::Conditions->value => 'Повний день, офіційне працевлаштування',
        ];
    }

    /** {Сьогодні}: the moment's date in the user's time zone (Core UserTime), dd.mm.yyyy. */
    public static function today(Carbon $now): string
    {
        return UserTime::now($now)->format('d.m.Y');
    }
}
