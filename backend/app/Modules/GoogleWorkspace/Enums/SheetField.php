<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\Enums;

/** A candidate field a spreadsheet column can be mapped to. */
enum SheetField: string
{
    case FullName = 'full_name';
    case Phone = 'phone';
    case Email = 'email';
    case Telegram = 'telegram';
    case Source = 'source';
    case UtmSource = 'utm_source';
    case UtmMedium = 'utm_medium';
    case UtmCampaign = 'utm_campaign';
    case UtmContent = 'utm_content';
    case UtmTerm = 'utm_term';
    case Vacancy = 'vacancy';
    case CreatedAt = 'created_at';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $f): string => $f->value, self::cases());
    }

    public function isUtm(): bool
    {
        return str_starts_with($this->value, 'utm_');
    }

    /**
     * Header words (lowercase, uk/ru/en) that suggest this field. Order of cases = priority.
     *
     * @return list<string>
     */
    public function hints(): array
    {
        return match ($this) {
            self::FullName => ['full_name', 'full name', 'name', "ім'я", 'імя', 'піб', 'фио', 'имя', 'прізвище', 'фамилия'],
            self::Phone => ['phone', 'телефон', 'тел', 'mobile'],
            self::Email => ['email', 'e-mail', 'mail', 'пошта', 'почта'],
            self::Telegram => ['telegram', 'телеграм', 'tg'],
            self::Source => ['source', 'джерело', 'источник'],
            self::UtmSource => ['utm_source'],
            self::UtmMedium => ['utm_medium'],
            self::UtmCampaign => ['utm_campaign'],
            self::UtmContent => ['utm_content'],
            self::UtmTerm => ['utm_term'],
            self::Vacancy => ['vacancy', 'position', 'вакансія', 'вакансия', 'посада', 'должность'],
            self::CreatedAt => ['created_at', 'date', 'timestamp', 'дата', 'позначка часу', 'отметка времени'],
        };
    }
}
