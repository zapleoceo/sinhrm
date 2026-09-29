<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Support;

/**
 * Generic option lists of the vacancy form (not company data, so they live in code; labels are translated in the
 * UI). Company dictionaries (branches, departments, cities, vacancy categories) live in the Directory module.
 */
final class VacancyOptions
{
    public const array EMPLOYMENT_TYPES = ['full_time', 'part_time', 'contract', 'internship', 'temporary'];

    public const array WORK_FORMATS = ['office', 'remote', 'hybrid'];

    public const array EXPERIENCE_LEVELS = ['none', 'lt_1', '1_3', '3_5', '5_plus'];

    public const array EDUCATION_LEVELS = ['any', 'secondary', 'vocational', 'incomplete_higher', 'higher'];

    /** CEFR levels + native. */
    public const array LANGUAGE_LEVELS = ['A1', 'A2', 'B1', 'B2', 'C1', 'C2', 'native'];

    /** ISO 639-1 codes offered in the form (names come from the browser, Intl.DisplayNames). */
    public const array LANGUAGES = ['en', 'uk', 'pl', 'de', 'fr', 'es', 'it', 'pt', 'cs', 'ro', 'tr', 'nl', 'sv', 'zh', 'ja', 'ko', 'ar', 'he', 'ru'];

    /** ISO 3166-1 alpha-2 codes offered in the form. */
    public const array COUNTRIES = ['UA', 'PL', 'DE', 'CZ', 'SK', 'RO', 'MD', 'LT', 'LV', 'EE', 'GE', 'KZ', 'AT', 'NL', 'ES', 'IT', 'FR', 'PT', 'GB', 'US', 'CA', 'IL', 'AE', 'TR'];

    public const array CURRENCIES = ['UAH', 'USD', 'EUR'];

    public const string DEFAULT_CURRENCY = 'UAH';

    /** Job sites without an employer API: only manual references (site + ad URL + date) for source attribution. */
    public const array EXTERNAL_SITES = ['work_ua', 'robota_ua', 'djinni', 'dou', 'linkedin', 'other'];

    /** @return array<string, list<string>> */
    public static function all(): array
    {
        return [
            'employment_types' => self::EMPLOYMENT_TYPES,
            'work_formats' => self::WORK_FORMATS,
            'experience_levels' => self::EXPERIENCE_LEVELS,
            'education_levels' => self::EDUCATION_LEVELS,
            'languages' => self::LANGUAGES,
            'language_levels' => self::LANGUAGE_LEVELS,
            'countries' => self::COUNTRIES,
            'currencies' => self::CURRENCIES,
            'external_sites' => self::EXTERNAL_SITES,
        ];
    }
}
