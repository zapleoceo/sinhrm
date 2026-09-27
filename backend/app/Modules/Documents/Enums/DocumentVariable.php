<?php

declare(strict_types=1);

namespace App\Modules\Documents\Enums;

/** Variables of document templates, written as {Name} in the body. Unknown {tokens} are rejected on save (422). */
enum DocumentVariable: string
{
    case FullName = 'ПІБ';
    case FirstName = "Ім'я";
    case Position = 'Посада';
    case Department = 'Відділ';
    case Branch = 'Філія';
    case HiredAt = 'Дата прийому';
    case FiredAt = 'Дата звільнення';
    case Manager = 'Керівник';
    case Today = 'Сьогодні';
    /** Offer variables (Recruiting offers; empty for employee documents). */
    case Salary = 'Зарплата';
    case StartDate = 'Дата виходу';
    case Conditions = 'Умови';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $v): string => $v->value, self::cases());
    }
}
