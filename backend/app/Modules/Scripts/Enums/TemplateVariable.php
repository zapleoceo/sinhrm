<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Enums;

/** Variables allowed in script templates, written as {Name} in the text. */
enum TemplateVariable: string
{
    case Name = "Ім'я";
    case Recruiter = 'Рекрутер';
    case Vacancy = 'Вакансія';
    case VacancyLink = 'Посилання на вакансію';
    case InterviewLink = 'Посилання на співбесіду';
    case Address = 'Адреса';

    public function token(): string
    {
        return '{'.$this->value.'}';
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $v): string => $v->value, self::cases());
    }
}
