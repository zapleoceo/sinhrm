<?php

declare(strict_types=1);

namespace App\Modules\Directory\Models;

use App\Modules\Directory\Database\Factories\VacancyCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/** Vacancy category (company dictionary): empty by default, filled only in the admin (Довідники). */
final class VacancyCategory extends DictionaryItem
{
    /** @use HasFactory<VacancyCategoryFactory> */
    use HasFactory;

    protected $table = 'vacancy_categories';

    protected static function newFactory(): VacancyCategoryFactory
    {
        return VacancyCategoryFactory::new();
    }
}
