<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Modules\Documents\Enums\DocumentVariable;
use App\Modules\People\Models\Employee;
use Illuminate\Support\Carbon;

/** Values of the template variables for one employee (dates as dd.mm.yyyy). Synthetic sample for the preview. */
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
            DocumentVariable::Today->value => $today->format('d.m.Y'),
        ];
    }

    /** @return array<string, string> fictional values for the editor preview (public repository: synthetic only) */
    public static function sample(Carbon $today): array
    {
        return [
            DocumentVariable::FullName->value => 'Олена Приклад',
            DocumentVariable::FirstName->value => 'Олена',
            DocumentVariable::Position->value => 'Адміністратор',
            DocumentVariable::Department->value => 'Навчальний відділ',
            DocumentVariable::Branch->value => 'Філія «Центр»',
            DocumentVariable::HiredAt->value => $today->copy()->subMonth()->format('d.m.Y'),
            DocumentVariable::FiredAt->value => $today->copy()->addYear()->format('d.m.Y'),
            DocumentVariable::Manager->value => 'Ірина Керівник',
            DocumentVariable::Today->value => $today->format('d.m.Y'),
        ];
    }
}
